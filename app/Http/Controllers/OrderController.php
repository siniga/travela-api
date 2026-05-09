<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Bundle;
use App\Models\Esim;
use App\Models\UserEsim;
use App\Models\Trip;
use App\Models\Kyc;
use App\Services\EvPayService;
use App\Services\VodacomSimManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(
        private readonly EvPayService $evpay,
        private readonly VodacomSimManagerService $vodacom,
    )
    {
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

    /**
     * FAKE / TEST ONLY: manually mark an order as paid.
     * Remove this endpoint after testing.
     */
    public function paymentPaidTest(int $orderId): JsonResponse
    {
        $order = Order::with(['orderItems.bundle'])->findOrFail($orderId);

        DB::beginTransaction();

        $order->payment_gateway = $order->payment_gateway ?? 'evpay';
        $order->payment_status = 'paid';
        $order->status = 'paid';
        $order->paid_at = now();
        $order->save();

        // Allocate an AVAILABLE eSIM if the user doesn't already have one.
        $assignment = UserEsim::where('user_id', $order->user_id)->with('esim')->latest('id')->first();

        if (! $assignment) {
            $esim = Esim::where('status', 'AVAILABLE')->lockForUpdate()->first();

            if (! $esim) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'No AVAILABLE eSIMs in inventory.',
                ], 409);
            }

            // Create/provision the SIM on Vodacom first (POST /api/sims).
            $createPayload = [
                'msisdn' => $esim->msisdn,
                'network_id' => (int) ($esim->network_id ?? 1),
                'status' => 'MANAGED',
            ];
            $createResp = $this->vodacom->post('/api/sims', [], $createPayload);

            if (! $createResp->successful()) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create SIM on Vodacom.',
                    'vodacom' => [
                        'status' => $createResp->status(),
                        'body' => $createResp->json(),
                    ],
                ], 502);
            }

            // Best-effort: persist sim_id if Vodacom returns one.
            $vodacomBody = $createResp->json();
            $vodacomSimId = data_get($vodacomBody, 'id')
                ?? data_get($vodacomBody, 'data.id')
                ?? data_get($vodacomBody, 'sim_id')
                ?? data_get($vodacomBody, 'data.sim_id');
            if ($vodacomSimId !== null && $vodacomSimId !== '') {
                $esim->sim_id = (int) $vodacomSimId;
            }

            $assignment = UserEsim::create([
                'user_id' => $order->user_id,
                'esim_id' => $esim->id,
            ]);

            $esim->status = 'MANAGED';
            $esim->save();

            $assignment->load('esim');
        } else {
            // If we already have a local assignment but the SIM isn't yet created on Vodacom, create it now.
            $esim = $assignment->esim;
            if ($esim && empty($esim->sim_id)) {
                $createPayload = [
                    'msisdn' => $esim->msisdn,
                    'network_id' => (int) ($esim->network_id ?? 1),
                    'status' => 'MANAGED',
                ];
                $createResp = $this->vodacom->post('/api/sims', [], $createPayload);

                if (! $createResp->successful()) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to create SIM on Vodacom (existing assignment).',
                        'vodacom' => [
                            'status' => $createResp->status(),
                            'body' => $createResp->json(),
                        ],
                    ], 502);
                }

                $vodacomBody = $createResp->json();
                $vodacomSimId = data_get($vodacomBody, 'id')
                    ?? data_get($vodacomBody, 'data.id')
                    ?? data_get($vodacomBody, 'sim_id')
                    ?? data_get($vodacomBody, 'data.sim_id');
                if ($vodacomSimId !== null && $vodacomSimId !== '') {
                    $esim->sim_id = (int) $vodacomSimId;
                }

                $esim->status = 'MANAGED';
                $esim->save();
                $assignment->load('esim');
            }
        }

        DB::commit();

        // Apply bundles (recharge) once per quantity (FAKE / TEST ONLY).
        $rechargeResults = $this->applyOrderBundlesToSim($order, $assignment->esim->msisdn);

        return response()->json([
            'success' => true,
            'message' => 'FAKE payment marked as paid.',
            'data' => [
                'order_id' => $order->id,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'paid_at' => optional($order->paid_at)->toIso8601String(),
                'msisdn' => $assignment->esim->msisdn,
                'recharge' => $rechargeResults,
            ],
        ]);
    }

    /**
     * FAKE / TEST ONLY: recharge/apply purchased bundles to a SIM.
     * Remove this after wiring the real payment callback.
     *
     * @return array<int, array<string, mixed>>
     */
    private function applyOrderBundlesToSim(Order $order, string $msisdn): array
    {
        $results = [];
        $items = $order->orderItems->where('type', 'bundle')->values();

        foreach ($items as $item) {
            $bundle = $item->bundle_id ? Bundle::find($item->bundle_id) : null;
            $productId = $bundle?->sim_bundle_id;

            if (! $productId) {
                $results[] = [
                    'order_item_id' => $item->id,
                    'error' => 'Missing sim_bundle_id for bundle.',
                ];
                continue;
            }

            $qty = (int) (($item->metadata['quantity'] ?? 1));
            if ($qty < 1) {
                $qty = 1;
            }

            for ($i = 1; $i <= $qty; $i++) {
                $reference = $this->generateRechargeReference($order->id, $item->id, $i);

                $payload = [
                    'msisdn' => $msisdn,
                    'network_id' => 1,
                    'product_id' => (int) $productId,
                    'reference' => $reference,
                    // "airtime_amount" expected as string in examples
                    'airtime_amount' => number_format((float) $item->price, 2, '.', ''),
                ];

                $resp = $this->vodacom->post('/api/recharge', [], $payload);

                $results[] = [
                    'reference' => $reference,
                    'payload' => $payload,
                    'status' => $resp->status(),
                    'ok' => $resp->successful(),
                    'body' => $resp->json(),
                ];
            }
        }

        return $results;
    }

    private function generateRechargeReference(int $orderId, int $orderItemId, int $i): string
    {
        $date = now()->format('Ymd');
        return "RECHARGE-{$date}-{$orderId}-{$orderItemId}-{$i}";
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

