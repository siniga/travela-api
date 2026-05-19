<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Trip;
use App\Models\Kyc;
use App\Services\EvPayService;
use App\Services\OrderRechargeService;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(
        private readonly EvPayService $evpay,
        private readonly OrderRechargeService $orderRecharge,
    ) {
    }

    public function getOrders(): JsonResponse
    {
        $orders = Order::with(['trip', 'orderItems.bundle', 'user', 'kyc'])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $orders,
        ]);
    }

    public function myOrders(Request $request): JsonResponse
    {
        $orders = Order::with(['trip', 'orderItems.bundle', 'kyc'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $orders,
        ]);
    }

    public function getOrdersByUser(int $userId): JsonResponse
    {
        $orders = Order::with(['trip', 'orderItems.bundle', 'user', 'kyc'])
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $orders,
        ]);
    }

    /**
     * Create or complete an order in one step (merged draft + finalize).
     */
    public function storeOrder(StoreOrderRequest $request): JsonResponse
    {
        $v = $request->validated();

        try {
            DB::beginTransaction();

            $order = Order::where('draft_id', $v['draft_id'])
                ->lockForUpdate()
                ->first();

            $paymentStatus = data_get($v, 'payment.status', 'pending');
            $newStatus = ($paymentStatus === 'paid') ? 'paid' : 'pending_payment';

            $meta = array_merge($order ? $this->metadataArray($order) : [], [
                'created_at' => $v['order_metadata']['created_at'],
                'checkoutMode' => $v['checkoutMode'] ?? null,
                'country' => $v['country'] ?? null,
                'countryName' => $v['countryName'] ?? null,
                'simType' => $v['simType'] ?? null,
                'finalized_at' => now()->toIso8601String(),
            ]);
            foreach (['msisdn', 'esim_id', 'user_esim_id'] as $simKey) {
                if (! empty($v[$simKey])) {
                    $meta[$simKey] = $v[$simKey];
                }
            }
            $meta['status'] = $newStatus;
            if (! empty($v['payment'] ?? [])) {
                $meta['payment'] = array_filter([
                    'status' => data_get($v, 'payment.status'),
                    'reference' => data_get($v, 'payment.reference'),
                    'method' => data_get($v, 'payment.method'),
                    'paid_at' => data_get($v, 'payment.paid_at'),
                ], fn ($x) => $x !== null && $x !== '');
            }

            if ($order && $order->status !== 'draft') {
                DB::commit();
                $order->load(['trip', 'orderItems', 'user', 'kyc']);

                return response()->json([
                    'success' => true,
                    'message' => 'Order already exists',
                    'data' => [
                        'order' => $order,
                        'draft_id' => $order->draft_id,
                        'status' => $order->status,
                        'total_amount' => $order->total_amount,
                        'currency' => $order->currency,
                    ],
                ], 200);
            }

            $validatedForKyc = [
                'user_id' => $v['user_id'],
                'kyc' => $v['kyc'],
                'trip' => $v['trip'],
            ];
            $this->createOrUpdateKyc($validatedForKyc);

            $orderPayload = [
                'draft_id' => $v['draft_id'],
                'user_id' => $v['user_id'],
                'status' => $newStatus,
                'subtotal' => $v['pricing']['subtotal'],
                'discount_amount' => $v['pricing']['discount_amount'],
                'discount_code' => $v['pricing']['discount_code'] ?? null,
                'total_amount' => $v['pricing']['total_amount'],
                'currency' => $v['pricing']['currency'],
                'source' => $v['order_metadata']['source'],
                'platform' => $v['order_metadata']['platform'],
                'metadata' => $meta,
            ];

            if ($paymentStatus === 'paid') {
                $orderPayload['payment_status'] = 'paid';
                $orderPayload['paid_at'] = data_get($v, 'payment.paid_at') ?: now();
                if ($ref = data_get($v, 'payment.reference')) {
                    $orderPayload['payment_reference'] = $ref;
                }
            }

            if (! $order) {
                $order = Order::create($orderPayload);
                $this->createTrip($order, $v['trip']);
                $this->createOrderItems($order, $v['items']);
            } else {
                $order->update($orderPayload);
                $this->updateTrip($order, $v['trip']);
                $order->orderItems()->delete();
                $this->createOrderItems($order, $v['items']);
            }

            DB::commit();

            $order->refresh();
            $order->load(['trip', 'orderItems', 'user', 'kyc']);

            $checkoutUrl = null;
            $paymentRef = null;
            if ($paymentStatus !== 'paid') {
                $this->evpay->prepare($order);
                $checkout = $this->evpay->createCheckoutUrl($order);
                $checkoutUrl = $checkout['checkout_url'] ?? null;
                $paymentRef = $checkout['payment_reference'] ?? null;
            } else {
                try {
                    $this->orderRecharge->rechargePaidOrder($order, [
                        'payment_id' => $order->gateway_payment_id,
                        'transaction_reference' => $order->payment_reference,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Order recharge fulfillment failed after storeOrder', [
                        'order_id' => $order->id,
                        'user_id' => $order->user_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Order stored successfully',
                'data' => [
                    'order' => $order,
                    'draft_id' => $order->draft_id,
                    'status' => $order->status,
                    'total_amount' => $order->total_amount,
                    'currency' => $order->currency,
                    'payment_reference' => $paymentRef,
                    'checkout_url' => $checkoutUrl,
                ],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to store order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified order.
     */
    public function show(string $draftId): JsonResponse
    {
        $order = Order::with(['trip', 'orderItems.bundle', 'user', 'kyc'])
            ->where('draft_id', $draftId)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $order
        ]);
    }

    /**
     * Update the specified order.
     */
    public function update(StoreOrderRequest $request, string $draftId): JsonResponse
    {
        try {
            DB::beginTransaction();

            $order = Order::where('draft_id', $draftId)->first();
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            $validated = $request->validated();

            // Update KYC record
            $this->createOrUpdateKyc($validated);

            // Update the order
            $this->updateOrder($order, $validated);

            // Update trip record
            $this->updateTrip($order, $validated['trip']);

            // Update order items (delete old ones and create new ones)
            $order->orderItems()->delete();
            $this->createOrderItems($order, $validated['items']);

            DB::commit();

            // Load relationships for response
            $order->load(['trip', 'orderItems', 'user', 'kyc']);

            return response()->json([
                'success' => true,
                'message' => 'Order updated successfully',
                'data' => $order
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified order from storage.
     */
    public function destroy(string $draftId): JsonResponse
    {
        try {
            $order = Order::where('draft_id', $draftId)->first();
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            $order->delete();

            return response()->json([
                'success' => true,
                'message' => 'Order deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create or update KYC record
     */
    private function createOrUpdateKyc(array $validated): Kyc
    {
        $kycData = $validated['kyc'];
        
        $kyc = Kyc::updateOrCreate(
            ['user_id' => $validated['user_id']],
            [
                'passport_id' => $kycData['passport_id'],
                'passport_country' => $kycData['passport_country'],
                'nationality' => $kycData['nationality'],
                'gender' => $kycData['gender'],
                'reason' => $kycData['reason_for_travel'],
                'arrival_date' => $validated['trip']['arrival_date'],
                'departure_date' => $validated['trip']['departure_date'],
            ]
        );

        return $kyc;
    }

    /**
     * Create order record
     */
    private function createOrder(array $validated): Order
    {
        $pricing = $validated['pricing'];
        $metadata = $validated['order_metadata'] ?? [];

        return Order::create([
            'draft_id' => $validated['draft_id'],
            'user_id' => $validated['user_id'],
            'status' => $metadata['status'] ?? 'pending_payment',
            'subtotal' => $pricing['subtotal'],
            'discount_amount' => $pricing['discount_amount'] ?? 0,
            'discount_code' => $pricing['discount_code'],
            'total_amount' => $pricing['total_amount'],
            'currency' => $pricing['currency'],
            'source' => $metadata['source'] ?? null,
            'platform' => $metadata['platform'] ?? null,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Update order record
     */
    private function updateOrder(Order $order, array $validated): void
    {
        $pricing = $validated['pricing'];
        $metadata = $validated['order_metadata'] ?? [];

        $order->update([
            'status' => $metadata['status'] ?? $order->status,
            'subtotal' => $pricing['subtotal'],
            'discount_amount' => $pricing['discount_amount'] ?? 0,
            'discount_code' => $pricing['discount_code'],
            'total_amount' => $pricing['total_amount'],
            'currency' => $pricing['currency'],
            'source' => $metadata['source'] ?? $order->source,
            'platform' => $metadata['platform'] ?? $order->platform,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Create trip record
     */
    private function createTrip(Order $order, array $tripData): Trip
    {
        return Trip::create([
            'order_id' => $order->id,
            'destination_country' => $tripData['destination_country'],
            'arrival_date' => $tripData['arrival_date'],
            'departure_date' => $tripData['departure_date'],
            'duration_days' => $tripData['duration_days'],
        ]);
    }

    /**
     * Update trip record
     */
    private function updateTrip(Order $order, array $tripData): void
    {
        $trip = $order->trip;
        
        if ($trip) {
            $trip->update([
                'destination_country' => $tripData['destination_country'],
                'arrival_date' => $tripData['arrival_date'],
                'departure_date' => $tripData['departure_date'],
                'duration_days' => $tripData['duration_days'],
            ]);
        } else {
            $this->createTrip($order, $tripData);
        }
    }

    /**
     * Create order items
     */
    private function createOrderItems(Order $order, array $items): void
    {
        foreach ($items as $item) {
            OrderItem::create([
                'order_id' => $order->id,
                'type' => $item['type'],
                'bundle_id' => $item['bundle_id'] ?? null,
                'bundle_name' => $item['bundle_name'],
                'data_amount' => $item['data_amount'] ?? null,
                'validity_days' => $item['validity_days'] ?? null,
                'price' => $item['price'],
                'currency' => $item['currency'],
            ]);
        }
    }
    
    /**
     * Normalize order.metadata to an array (handles array cast and legacy JSON strings).
     *
     * @return array<string, mixed>
     */
    private function metadataArray(Order $order): array
    {
        $raw = $order->getAttributes()['metadata'] ?? null;

        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        $value = $order->metadata;

        return is_array($value) ? $value : [];
    }
}

