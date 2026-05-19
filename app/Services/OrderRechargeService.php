<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\Esim;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\UserEsim;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderRechargeService
{
    private const FULFILLED_STATUSES = ['success', 'sent', 'queued'];

    private const TERMINAL_SUCCESS_STATUSES = ['success'];

    public function __construct(
        private readonly VodacomSimManagerService $vodacom,
    ) {
    }

    /**
     * @param  array{payment_id?: string|null, transaction_reference?: string|null}|null  $evpayContext
     * @return array{processed:int, skipped:int, failed:int, errors:array<int, string>, recharge_status?: string}
     */
    public function rechargePaidOrder(Order $order, ?array $evpayContext = null): array
    {
        return $this->fulfillOrder($order, $evpayContext);
    }

    /**
     * @param  array{payment_id?: string|null, transaction_reference?: string|null}|null  $evpayContext
     * @return array{processed:int, skipped:int, failed:int, errors:array<int, string>, recharge_status?: string}
     */
    public function fulfillOrder(Order $order, ?array $evpayContext = null): array
    {
        $order->refresh();
        $order->loadMissing(['orderItems.bundle']);

        $paymentId = $evpayContext['payment_id'] ?? $order->gateway_payment_id;
        $transactionRef = $evpayContext['transaction_reference'] ?? $order->payment_reference;

        if (! $this->orderIsPaid($order)) {
            return ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        }

        if ($this->orderRechargeIsSuccessful($order)) {
            Log::info('Order recharge skipped: already successful', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'recharge_reference' => $order->recharge_reference,
                'recharge_transaction_id' => $order->recharge_transaction_id,
                'recharge_status' => $order->recharge_status,
            ]);

            return [
                'processed' => 0,
                'skipped' => $this->rechargeableItems($order)->count(),
                'failed' => 0,
                'errors' => [],
                'recharge_status' => 'success',
            ];
        }

        if ($this->shouldSkipRechargeForPayment($order, $paymentId, $transactionRef)) {
            Log::info('Order recharge skipped: already fulfilled for this payment', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'payment_id' => $paymentId,
                'transaction_reference' => $transactionRef,
                'recharge_status' => $order->recharge_status,
            ]);

            return [
                'processed' => 0,
                'skipped' => $this->rechargeableItems($order)->count(),
                'failed' => 0,
                'errors' => [],
                'recharge_status' => 'success',
            ];
        }

        $assignment = $this->resolveUserEsimForOrder($order);
        if (! $assignment?->esim) {
            $userEsimCount = UserEsim::where('user_id', $order->user_id)->count();
            $message = 'No user eSIM assignment with MSISDN found for this order user.';
            Log::error('Order recharge failed: missing user eSIM', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'payment_id' => $paymentId,
                'transaction_reference' => $transactionRef,
                'user_esim_assignments_count' => $userEsimCount,
                'order_metadata_keys' => array_keys($this->orderMetadata($order)),
                'hint' => 'Assign an eSIM via admin user-esims, or pass msisdn/esim_id/user_esim_id on the order payload.',
            ]);
            $this->setOrderRechargeStatus($order, 'pending_esim', $message, $paymentId, $transactionRef);

            return [
                'processed' => 0,
                'skipped' => 0,
                'failed' => 0,
                'errors' => [$message],
                'recharge_status' => 'pending_esim',
            ];
        }

        $esim = $assignment->esim;
        if (! $esim->msisdn || $esim->network_id === null) {
            $message = 'Assigned eSIM is missing msisdn or network_id.';
            Log::error('Order recharge failed: incomplete eSIM inventory record', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'user_esim_id' => $assignment->id,
                'esim_id' => $esim->id,
                'msisdn' => $esim->msisdn,
                'network_id' => $esim->network_id,
            ]);
            $this->setOrderRechargeStatus($order, 'pending_esim', $message, $paymentId, $transactionRef, $assignment->id);

            return [
                'processed' => 0,
                'skipped' => 0,
                'failed' => 0,
                'errors' => [$message],
                'recharge_status' => 'pending_esim',
            ];
        }

        $this->linkOrderToUserEsim($order, $assignment, $paymentId, $transactionRef);
        $this->setOrderRechargeStatus($order, 'in_progress', null, $paymentId, $transactionRef, $assignment->id);

        $result = ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        foreach ($this->rechargeableItems($order) as $item) {
            if ($this->itemAlreadyFulfilled($item)) {
                $result['skipped']++;

                continue;
            }

            $bundle = $item->bundle;
            if (! $bundle?->sim_bundle_id) {
                $message = "Order item {$item->id} has no Vodacom product_id (bundles.sim_bundle_id).";
                Log::error('Order recharge validation failed: missing product_id', [
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'bundle_id' => $item->bundle_id,
                    'user_id' => $order->user_id,
                    'user_esim_id' => $assignment->id,
                ]);
                $this->markItemRechargeFailed($item, $message, null, $order, $assignment);
                $result['failed']++;
                $result['errors'][] = $message;

                continue;
            }

            $reference = $this->generateRechargeReference($order, $item);
            $payload = $this->buildRechargePayload($esim, $bundle, $item, $order, $reference);
            $validation = $this->validateRechargePayload($payload);

            if ($validation !== []) {
                $message = 'Recharge payload validation failed: missing or invalid '.implode(', ', $validation);
                Log::error('Order recharge validation failed', $this->rechargeLogContext(
                    $order,
                    $assignment,
                    $item,
                    $bundle,
                    $payload,
                    ['validation_errors' => $validation]
                ));
                $this->markItemRechargeFailed($item, $message, $payload, $order, $assignment);
                $result['failed']++;
                $result['errors'][] = $message;

                continue;
            }

            $rechargeLog = $this->rechargePayloadLog($order, $assignment, $payload);

            Log::info('Vodacom recharge request payload', $rechargeLog);

            try {
                $response = $this->vodacom->post('/api/recharge', [], $payload, $rechargeLog);
                $body = $response->json();
                $responseBody = is_array($body) ? $body : ['raw' => (string) $response->body()];
                $httpStatus = $response->status();
                $vodacomStatus = $this->interpretVodacomRechargeStatus($httpStatus, $responseBody);

                $this->logRechargeResponse($rechargeLog, $httpStatus, $responseBody, $vodacomStatus);

                $transactionId = $this->extractVodacomTransactionId($responseBody);

                if ($this->isVodacomResponseSuccess($responseBody, $httpStatus)) {
                    $this->markRechargeSuccess($order, $reference, $transactionId, $responseBody, $httpStatus);
                    $this->persistItemRecharge($item, $this->buildItemRechargeRecord(
                        $reference,
                        $transactionId,
                        'success',
                        $httpStatus,
                        $payload,
                        $responseBody
                    ));
                    $result['processed']++;
                } elseif ($this->isVodacomResponseFailed($responseBody, $httpStatus)) {
                    $this->markRechargeFailed($order, $reference, $transactionId, $responseBody, $httpStatus);
                    $this->persistItemRecharge($item, $this->buildItemRechargeRecord(
                        $reference,
                        $transactionId,
                        'failed',
                        $httpStatus,
                        $payload,
                        $responseBody
                    ));
                    $result['failed']++;
                    $result['errors'][] = "Vodacom recharge failed for item {$item->id} (HTTP {$httpStatus}).";
                } elseif ($response->successful() && in_array($vodacomStatus, ['queued', 'pending'], true)) {
                    $this->persistOrderRechargeInProgress($order, $reference, $transactionId, $responseBody, $vodacomStatus, $httpStatus);
                    $this->persistItemRecharge($item, $this->buildItemRechargeRecord(
                        $reference,
                        $transactionId,
                        $vodacomStatus,
                        $httpStatus,
                        $payload,
                        $responseBody
                    ));
                    $result['processed']++;
                } else {
                    $this->markRechargeFailed($order, $reference, $transactionId, $responseBody, $httpStatus);
                    $this->persistItemRecharge($item, $this->buildItemRechargeRecord(
                        $reference,
                        $transactionId,
                        'pending_retry',
                        $httpStatus,
                        $payload,
                        $responseBody,
                        $responseBody['error'] ?? 'Unexpected Vodacom response'
                    ));
                    $result['failed']++;
                    $result['errors'][] = "Vodacom recharge failed for item {$item->id} (HTTP {$httpStatus}, status {$vodacomStatus}).";
                }
            } catch (\Throwable $e) {
                Log::error('Order recharge request exception', array_merge(
                    $this->rechargeLogContext($order, $assignment, $item, $bundle, $payload),
                    ['exception' => $e->getMessage()]
                ));

                $this->persistItemRecharge($item, [
                    'reference' => $reference,
                    'status' => 'pending_retry',
                    'requested_at' => now()->toIso8601String(),
                    'payload' => $payload,
                    'error' => $e->getMessage(),
                ]);

                $result['failed']++;
                $result['errors'][] = $e->getMessage();
            }
        }

        $rechargeStatus = $this->deriveOrderRechargeStatus($order, $result);
        $this->recordFulfillmentSummary($order, $result, $rechargeStatus, $paymentId, $transactionRef, $assignment->id);
        $result['recharge_status'] = $rechargeStatus;

        return $result;
    }

    private function orderIsPaid(Order $order): bool
    {
        return $order->payment_status === 'paid' || $order->status === 'paid';
    }

    private function shouldSkipRechargeForPayment(Order $order, ?string $paymentId, ?string $transactionRef): bool
    {
        if ($this->orderRechargeIsSuccessful($order)) {
            return true;
        }

        $meta = $this->orderMetadata($order);
        $storedPaymentId = $meta['recharge_evpay_payment_id'] ?? $order->gateway_payment_id;
        $storedRef = $meta['recharge_evpay_transaction_reference'] ?? $order->payment_reference;

        if ($paymentId && $storedPaymentId === $paymentId && $this->allRechargeableItemsFulfilled($order)) {
            return true;
        }

        if ($transactionRef && $storedRef === $transactionRef && $this->allRechargeableItemsFulfilled($order)) {
            return true;
        }

        return false;
    }

    private function orderRechargeIsSuccessful(Order $order): bool
    {
        return $order->recharge_status === 'success';
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    public function markRechargeSuccess(
        Order $order,
        string $rechargeReference,
        ?string $transactionId,
        array $responseBody,
        int $httpStatus,
    ): void {
        DB::transaction(function () use ($order, $rechargeReference, $transactionId, $responseBody, $httpStatus) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->recharge_status === 'success') {
                return;
            }

            $order->fill([
                'recharge_status' => 'success',
                'recharge_reference' => $rechargeReference,
                'recharge_transaction_id' => $transactionId,
                'recharge_response' => $responseBody,
                'recharge_completed_at' => now(),
                'recharge_http_status' => $httpStatus,
            ]);
            $this->syncRechargeMetadata($order, 'success', $rechargeReference, $transactionId, $responseBody);
            $order->save();

            Log::info('Order recharge marked successful', [
                'order_id' => $order->id,
                'recharge_reference' => $rechargeReference,
                'recharge_transaction_id' => $transactionId,
                'recharge_status' => 'success',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    public function markRechargeFailed(
        Order $order,
        ?string $rechargeReference,
        ?string $transactionId,
        array $responseBody,
        int $httpStatus,
    ): void {
        DB::transaction(function () use ($order, $rechargeReference, $transactionId, $responseBody, $httpStatus) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->recharge_status === 'success') {
                return;
            }

            $order->fill([
                'recharge_status' => 'failed',
                'recharge_reference' => $rechargeReference ?? $order->recharge_reference,
                'recharge_transaction_id' => $transactionId ?? $order->recharge_transaction_id,
                'recharge_response' => $responseBody,
                'recharge_completed_at' => null,
                'recharge_http_status' => $httpStatus,
            ]);
            $this->syncRechargeMetadata($order, 'failed', $rechargeReference, $transactionId, $responseBody);
            $order->save();

            Log::warning('Order recharge marked failed', [
                'order_id' => $order->id,
                'recharge_reference' => $rechargeReference,
                'recharge_transaction_id' => $transactionId,
                'recharge_status' => 'failed',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    private function persistOrderRechargeInProgress(
        Order $order,
        string $rechargeReference,
        ?string $transactionId,
        array $responseBody,
        string $status,
        int $httpStatus,
    ): void {
        DB::transaction(function () use ($order, $rechargeReference, $transactionId, $responseBody, $status, $httpStatus) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->recharge_status === 'success') {
                return;
            }

            $order->fill([
                'recharge_status' => $status,
                'recharge_reference' => $rechargeReference,
                'recharge_transaction_id' => $transactionId,
                'recharge_response' => $responseBody,
                'recharge_http_status' => $httpStatus,
            ]);
            $this->syncRechargeMetadata($order, $status, $rechargeReference, $transactionId, $responseBody);
            $order->save();
        });
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    private function syncRechargeMetadata(
        Order $order,
        string $status,
        ?string $rechargeReference,
        ?string $transactionId,
        array $responseBody,
    ): void {
        $meta = $this->orderMetadata($order);
        $meta['recharge_status'] = $status;
        if ($rechargeReference) {
            $meta['recharge_reference'] = $rechargeReference;
        }
        if ($transactionId) {
            $meta['recharge_transaction_id'] = $transactionId;
        }
        $meta['recharge_response'] = $responseBody;
        $order->metadata = $meta;
    }

    /**
     * @param  array<string, mixed>  $responseBody
     * @return array<string, mixed>
     */
    private function buildItemRechargeRecord(
        string $reference,
        ?string $transactionId,
        string $status,
        int $httpStatus,
        array $payload,
        array $responseBody,
        ?string $error = null,
    ): array {
        $record = [
            'reference' => $reference,
            'recharge_reference' => $reference,
            'recharge_transaction_id' => $transactionId,
            'status' => $status,
            'requested_at' => now()->toIso8601String(),
            'http_status' => $httpStatus,
            'payload' => $payload,
            'response' => $responseBody,
            'recharge_response' => $responseBody,
        ];

        if ($error) {
            $record['error'] = $error;
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    private function extractVodacomTransactionId(array $responseBody): ?string
    {
        foreach (['transaction_id', 'transactionId', 'TransactionId', 'id'] as $key) {
            $value = $responseBody[$key] ?? null;
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    private function isVodacomResponseSuccess(array $responseBody, int $httpStatus): bool
    {
        if ($httpStatus < 200 || $httpStatus >= 300) {
            return false;
        }

        $status = strtoupper((string) ($responseBody['status'] ?? $responseBody['Status'] ?? ''));

        if ($status === 'SUCCESS') {
            return true;
        }

        return in_array(strtolower($status), ['success', 'successful', 'completed', 'complete', 'approved'], true)
            || filter_var($responseBody['success'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    private function isVodacomResponseFailed(array $responseBody, int $httpStatus): bool
    {
        if ($httpStatus >= 400) {
            return true;
        }

        $status = strtoupper((string) ($responseBody['status'] ?? $responseBody['Status'] ?? ''));

        if (in_array($status, ['FAILED', 'FAILURE', 'ERROR', 'REJECTED', 'DECLINED'], true)) {
            return true;
        }

        return array_key_exists('success', $responseBody)
            && filter_var($responseBody['success'], FILTER_VALIDATE_BOOLEAN) === false;
    }

    private function allRechargeableItemsFulfilled(Order $order): bool
    {
        $items = $this->rechargeableItems($order);
        if ($items->isEmpty()) {
            return false;
        }

        return $items->every(fn (OrderItem $item) => $this->itemAlreadyFulfilled($item));
    }

    /**
     * Resolve SIM for recharge: order metadata → user assignment → auto-link unassigned inventory.
     */
    private function resolveUserEsimForOrder(Order $order): ?UserEsim
    {
        if (! $order->user_id) {
            return null;
        }

        $meta = $this->orderMetadata($order);

        if (! empty($meta['user_esim_id'])) {
            $assignment = UserEsim::query()
                ->where('id', $meta['user_esim_id'])
                ->where('user_id', $order->user_id)
                ->with('esim')
                ->first();
            if ($assignment?->esim) {
                return $assignment;
            }
        }

        if (! empty($meta['esim_id'])) {
            $assignment = UserEsim::query()
                ->where('user_id', $order->user_id)
                ->where('esim_id', $meta['esim_id'])
                ->with('esim')
                ->first();
            if ($assignment?->esim) {
                return $assignment;
            }
        }

        if (! empty($meta['msisdn']) && is_string($meta['msisdn'])) {
            $assignment = $this->resolveOrAssignByMsisdn($order->user_id, $meta['msisdn']);
            if ($assignment) {
                return $assignment;
            }
        }

        if (! empty($meta['recharge']['user_esim_id'])) {
            $assignment = UserEsim::query()
                ->where('id', $meta['recharge']['user_esim_id'])
                ->where('user_id', $order->user_id)
                ->with('esim')
                ->first();
            if ($assignment?->esim) {
                return $assignment;
            }
        }

        return UserEsim::query()
            ->where('user_id', $order->user_id)
            ->with('esim')
            ->whereHas('esim', fn ($q) => $q->whereNotNull('msisdn')->where('msisdn', '!=', ''))
            ->orderBy('id')
            ->first();
    }

    private function resolveOrAssignByMsisdn(int $userId, string $msisdn): ?UserEsim
    {
        $esim = Esim::findByMsisdn($msisdn);
        if (! $esim) {
            return null;
        }

        $owned = UserEsim::query()
            ->where('user_id', $userId)
            ->where('esim_id', $esim->id)
            ->with('esim')
            ->first();
        if ($owned) {
            return $owned;
        }

        $taken = UserEsim::where('esim_id', $esim->id)->exists();
        if ($taken) {
            Log::warning('Order recharge: MSISDN on order belongs to another user', [
                'user_id' => $userId,
                'msisdn' => $esim->msisdn,
                'esim_id' => $esim->id,
            ]);

            return null;
        }

        return UserEsim::create([
            'user_id' => $userId,
            'esim_id' => $esim->id,
        ])->load('esim');
    }

    /**
     * @return \Illuminate\Support\Collection<int, OrderItem>
     */
    private function rechargeableItems(Order $order)
    {
        return $order->orderItems
            ->filter(fn (OrderItem $item) => $item->type === 'bundle')
            ->filter(fn (OrderItem $item) => $item->bundle_id !== null)
            ->values();
    }

    private function itemAlreadyFulfilled(OrderItem $item): bool
    {
        $order = $item->relationLoaded('order') ? $item->order : $item->order()->first();
        if ($order && $this->orderRechargeIsSuccessful($order)) {
            return true;
        }

        $recharge = $this->itemMetadataArray($item)['recharge'] ?? [];

        if (! is_array($recharge)) {
            return false;
        }

        $status = $recharge['status'] ?? null;
        $reference = $recharge['reference'] ?? $recharge['recharge_reference'] ?? null;

        return $reference && in_array($status, self::TERMINAL_SUCCESS_STATUSES, true);
    }

    /**
     * product_id comes from bundles.sim_bundle_id (Vodacom catalog id for the purchased bundle).
     *
     * @return array<string, string|int>|null
     */
    private function buildRechargePayload(
        Esim $esim,
        Bundle $bundle,
        OrderItem $item,
        Order $order,
        string $reference,
    ): ?array {
        $airtime = $this->resolveAirtimeAmount($item, $bundle, $order);
        if ($airtime === null) {
            return null;
        }

        return [
            'msisdn' => $esim->msisdn,
            'network_id' => (int) $esim->network_id,
            'product_id' => (int) $bundle->sim_bundle_id,
            'reference' => $reference,
            'airtime_amount' => $airtime,
        ];
    }

    /**
     * @return list<string> Field names that failed validation (empty = valid).
     */
    private function validateRechargePayload(?array $payload): array
    {
        if ($payload === null) {
            return ['payload'];
        }

        $errors = [];

        if (empty($payload['msisdn']) || ! is_string($payload['msisdn'])) {
            $errors[] = 'msisdn';
        }

        if (! isset($payload['network_id']) || ! is_numeric($payload['network_id'])) {
            $errors[] = 'network_id';
        }

        if (empty($payload['product_id']) || ! is_numeric($payload['product_id'])) {
            $errors[] = 'product_id';
        }

        if (empty($payload['reference']) || ! is_string($payload['reference'])) {
            $errors[] = 'reference';
        }

        if (! isset($payload['airtime_amount']) || ! is_numeric($payload['airtime_amount'])) {
            $errors[] = 'airtime_amount';
        }

        return $errors;
    }

    private function resolveAirtimeAmount(OrderItem $item, Bundle $bundle, Order $order): ?string
    {
        $meta = is_array($bundle->metadata) ? $bundle->metadata : [];
        foreach (['vodacom_airtime_amount', 'airtime_amount', 'recharge_amount'] as $key) {
            if (isset($meta[$key]) && $meta[$key] !== '' && $meta[$key] !== null) {
                return $this->formatAirtimeAmount($meta[$key]);
            }
        }

        if ($bundle->price_tzs !== null && (int) $bundle->price_tzs >= 1) {
            return $this->formatAirtimeAmount($bundle->price_tzs);
        }

        $byProduct = config('services.vodacom_sim.recharge_airtime_by_product_id', []);
        $productId = (int) $bundle->sim_bundle_id;
        if (isset($byProduct[$productId])) {
            return $this->formatAirtimeAmount($byProduct[$productId]);
        }
        if (isset($byProduct[(string) $productId])) {
            return $this->formatAirtimeAmount($byProduct[(string) $productId]);
        }

        return null;
    }

    private function formatAirtimeAmount(mixed $value): string
    {
        if (is_string($value) && preg_match('/^\d+$/', trim($value))) {
            return trim($value);
        }

        return (string) max(1, (int) round((float) $value));
    }

    private function generateRechargeReference(Order $order, OrderItem $item): string
    {
        $existing = $this->itemMetadataArray($item)['recharge']['reference'] ?? null;
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        return sprintf('RCH-%s-%d-%d', now()->format('Ymd'), $order->id, $item->id);
    }

    /**
     * @param  array<string, string|int>  $payload
     * @return array<string, mixed>
     */
    private function rechargePayloadLog(Order $order, UserEsim $assignment, array $payload): array
    {
        return [
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'user_esim_id' => $assignment->id,
            'msisdn' => $payload['msisdn'] ?? null,
            'network_id' => $payload['network_id'] ?? null,
            'product_id' => $payload['product_id'] ?? null,
            'reference' => $payload['reference'] ?? null,
            'airtime_amount' => $payload['airtime_amount'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $rechargeLog
     * @param  array<string, mixed>  $responseBody
     */
    private function logRechargeResponse(
        array $rechargeLog,
        int $httpStatus,
        array $responseBody,
        string $vodacomStatus,
    ): void {
        $context = array_merge($rechargeLog, [
            'http_status' => $httpStatus,
            'vodacom_status' => $vodacomStatus,
            'response_body' => $responseBody,
        ]);

        if ($httpStatus >= 200 && $httpStatus < 300 && in_array($vodacomStatus, ['success', 'queued', 'pending'], true)) {
            Log::info('Vodacom recharge response received', $context);
        } else {
            Log::warning('Vodacom recharge response received', $context);
        }
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    private function interpretVodacomRechargeStatus(int $httpStatus, array $responseBody): string
    {
        if ($httpStatus < 200 || $httpStatus >= 300) {
            return 'pending_retry';
        }

        if ($httpStatus === 202) {
            return 'queued';
        }

        $statusText = strtolower((string) (
            $responseBody['status']
            ?? $responseBody['Status']
            ?? $responseBody['state']
            ?? ''
        ));
        $message = strtolower((string) ($responseBody['message'] ?? $responseBody['Message'] ?? ''));

        if (
            str_contains($message, 'queued')
            || str_contains($statusText, 'queued')
            || str_contains($message, 'callback')
        ) {
            return 'queued';
        }

        if (
            in_array($statusText, ['success', 'successful', 'completed', 'complete', 'approved'], true)
            || filter_var($responseBody['success'] ?? false, FILTER_VALIDATE_BOOLEAN)
        ) {
            return 'success';
        }

        if (in_array($statusText, ['pending', 'processing', 'in_progress', 'submitted'], true)) {
            return 'pending';
        }

        if (str_contains($message, 'pending') || str_contains($message, 'processing')) {
            return 'pending';
        }

        return 'success';
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function rechargeLogContext(
        Order $order,
        UserEsim $assignment,
        OrderItem $item,
        Bundle $bundle,
        ?array $payload,
        array $extra = [],
    ): array {
        return array_merge([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'user_esim_id' => $assignment->id,
            'order_item_id' => $item->id,
            'bundle_id' => $bundle->id,
            'msisdn' => $payload['msisdn'] ?? $assignment->esim?->msisdn,
            'network_id' => $payload['network_id'] ?? $assignment->esim?->network_id,
            'product_id' => $payload['product_id'] ?? $bundle->sim_bundle_id,
            'reference' => $payload['reference'] ?? null,
            'airtime_amount' => $payload['airtime_amount'] ?? null,
            'request_payload' => $payload,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function markItemRechargeFailed(
        OrderItem $item,
        string $message,
        ?array $payload,
        Order $order,
        UserEsim $assignment,
    ): void {
        $this->persistItemRecharge($item, [
            'reference' => $payload['reference'] ?? $this->generateRechargeReference($order, $item),
            'status' => 'pending_retry',
            'requested_at' => now()->toIso8601String(),
            'payload' => $payload,
            'error' => $message,
        ]);
    }

    /**
     * @param  array<string, mixed>  $recharge
     */
    private function persistItemRecharge(OrderItem $item, array $recharge): void
    {
        $meta = $this->itemMetadataArray($item);
        $meta['recharge'] = $recharge;
        $item->metadata = $meta;
        $item->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function itemMetadataArray(OrderItem $item): array
    {
        $raw = $item->getAttributes()['metadata'] ?? null;

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        $value = $item->metadata;

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderMetadata(Order $order): array
    {
        $meta = $order->metadata;

        return is_array($meta) ? $meta : [];
    }

    private function linkOrderToUserEsim(
        Order $order,
        UserEsim $assignment,
        ?string $paymentId,
        ?string $transactionRef,
    ): void {
        $meta = $this->orderMetadata($order);
        $meta['user_esim_id'] = $assignment->id;
        $meta['esim_id'] = $assignment->esim_id;
        if ($assignment->esim?->msisdn) {
            $meta['msisdn'] = $assignment->esim->msisdn;
        }
        $order->metadata = $meta;
        $order->save();
    }

    private function setOrderRechargeStatus(
        Order $order,
        string $status,
        ?string $error,
        ?string $paymentId,
        ?string $transactionRef,
        ?int $userEsimId = null,
    ): void {
        DB::transaction(function () use ($order, $status, $error, $paymentId, $transactionRef, $userEsimId) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->recharge_status !== 'success') {
                $order->recharge_status = $status;
            }

            $meta = $this->orderMetadata($order);
            $meta['recharge_status'] = $order->recharge_status ?? $status;
            if ($error) {
                $meta['recharge_error'] = $error;
            }
            if ($paymentId) {
                $meta['recharge_evpay_payment_id'] = $paymentId;
            }
            if ($transactionRef) {
                $meta['recharge_evpay_transaction_reference'] = $transactionRef;
            }
            if ($userEsimId) {
                $meta['recharge'] = array_merge(is_array($meta['recharge'] ?? null) ? $meta['recharge'] : [], [
                    'user_esim_id' => $userEsimId,
                ]);
            }
            $meta['recharge_last_attempt_at'] = now()->toIso8601String();
            $order->metadata = $meta;
            $order->save();
        });
    }

    /**
     * @param  array{processed:int, skipped:int, failed:int, errors:array<int, string>}  $result
     */
    private function deriveOrderRechargeStatus(Order $order, array $result): string
    {
        $order->refresh();

        if ($order->recharge_status) {
            return $order->recharge_status;
        }

        if ($result['failed'] > 0) {
            return 'pending_retry';
        }

        if ($result['processed'] > 0) {
            return 'success';
        }

        return 'pending_retry';
    }

    /**
     * @param  array{processed:int, skipped:int, failed:int, errors:array<int, string>}  $result
     */
    private function recordFulfillmentSummary(
        Order $order,
        array $result,
        string $rechargeStatus,
        ?string $paymentId,
        ?string $transactionRef,
        ?int $userEsimId,
    ): void {
        DB::transaction(function () use ($order, $result, $rechargeStatus, $paymentId, $transactionRef, $userEsimId) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->recharge_status) {
                $order->recharge_status = $rechargeStatus;
            }

            $meta = $this->orderMetadata($order);
            $meta['recharge_status'] = $order->recharge_status ?? $rechargeStatus;
            $meta['fulfillment'] = [
                'last_run_at' => now()->toIso8601String(),
                'processed' => $result['processed'],
                'skipped' => $result['skipped'],
                'failed' => $result['failed'],
                'errors' => $result['errors'],
            ];
            if ($paymentId) {
                $meta['recharge_evpay_payment_id'] = $paymentId;
            }
            if ($transactionRef) {
                $meta['recharge_evpay_transaction_reference'] = $transactionRef;
            }
            if ($userEsimId) {
                $meta['recharge'] = array_merge(is_array($meta['recharge'] ?? null) ? $meta['recharge'] : [], [
                    'user_esim_id' => $userEsimId,
                ]);
            }
            unset($meta['recharge_error']);
            if ($rechargeStatus === 'pending_retry' || $rechargeStatus === 'pending_esim') {
                $meta['recharge_error'] = $result['errors'][0] ?? $meta['recharge_error'] ?? null;
            }
            $order->metadata = $meta;
            $order->save();
        });
    }
}
