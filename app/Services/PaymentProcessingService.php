<?php

namespace App\Services;

use App\Models\EvPayWebhookDelivery;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\EvPay\EvPayException;
use App\Services\EvPay\EvPayService;
use App\Support\TanzaniaPhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentProcessingService
{
    public const MOBILE_MONEY_EVENTS = [
        'payment.succeeded',
        'payment.settled',
        'payment.failed',
        'payment.on_hold',
        'payment.refunded',
        'payment.reversed',
    ];

    public function __construct(
        private readonly EvPayService $evpay,
        private readonly SimAssignmentService $simAssignment,
    ) {
    }

    /**
     * @param  array{
     *     operator: string,
     *     phone: string,
     *     amount?: float|int|string|null,
     *     order_id?: int|null,
     *     product?: string|null,
     *     payer_name?: string|null,
     *     narrative?: string|null,
     *     currency?: string|null,
     *     request_id?: string|null
     * }  $input
     */
    public function initiateMobileMoney(User $user, array $input): Payment
    {
        $operator = $input['operator'];
        if (! in_array($operator, Payment::OPERATORS, true)) {
            throw ValidationException::withMessages([
                'operator' => 'Operator must be one of: '.implode(', ', Payment::OPERATORS),
            ]);
        }

        try {
            $phone = TanzaniaPhoneNumber::normalize((string) $input['phone']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'phone' => $e->getMessage(),
            ]);
        }

        $order = null;
        if (! empty($input['order_id'])) {
            $order = Order::query()->find($input['order_id']);
            if (! $order) {
                throw ValidationException::withMessages(['order_id' => 'Order not found.']);
            }
            if ((int) $order->user_id !== (int) $user->id && ! $user->isAdmin()) {
                throw ValidationException::withMessages(['order_id' => 'You cannot pay for this order.']);
            }
            if ($order->payment_status === 'paid') {
                throw ValidationException::withMessages(['order_id' => 'This order is already paid.']);
            }
        }

        $amount = $this->resolveAmountTzs($order, $input['amount'] ?? null);
        if ($amount < 100) {
            throw ValidationException::withMessages(['amount' => 'Minimum payment amount is 100 TZS.']);
        }

        $payment = $this->resolveRetryablePayment($user, $input['request_id'] ?? null, $order, $operator, $phone, $amount);

        if ($payment && in_array($payment->status, Payment::FULFILLABLE_STATUSES, true)) {
            return $payment;
        }

        if (! $payment) {
            $requestId = 'PAY-'.strtoupper((string) Str::ulid());
            $product = $input['product']
                ?? ($order ? 'Order #'.$order->draft_id : 'Mobile money payment');
            $narrative = $input['narrative']
                ?? ($order ? 'Payment for Order '.$order->draft_id : 'Mobile money payment');
            $payerName = $input['payer_name'] ?? $user->name;

            $payload = $this->buildEvPayPayload(
                $operator,
                $amount,
                $product,
                $phone,
                $requestId,
                $payerName,
                $narrative,
            );
            $rawBody = $this->evpay->encodeJson($payload);

            $payment = Payment::create([
                'request_id' => $requestId,
                'user_id' => $user->id,
                'order_id' => $order?->id,
                'provider' => Payment::PROVIDER_EVPAY,
                'payment_method' => Payment::METHOD_MOBILE_MONEY,
                'operator' => $operator,
                'phone_number' => $phone,
                'amount' => $amount,
                'currency' => 'TZS',
                'product' => $product,
                'status' => Payment::STATUS_PENDING,
                'request_payload' => $payload,
                'request_body' => $rawBody,
            ]);

            if ($order) {
                $order->payment_gateway = Payment::PROVIDER_EVPAY;
                $order->payment_status = 'pending';
                $order->payment_reference = $payment->request_id;
                if ($order->status !== 'paid') {
                    $order->status = 'pending_payment';
                }
                $order->save();
            }
        }

        try {
            $response = $this->evpay->createMobileMoneyPayment(
                $payment->request_payload ?? [],
                $payment->request_id,
                $payment->request_body,
            );
        } catch (EvPayException $e) {
            $payment->provider_message = $e->getMessage();
            if ($e->httpStatus < 500 && $e->httpStatus !== 409) {
                $payment->status = Payment::STATUS_FAILED;
            }
            $payment->save();

            throw $e;
        }

        $this->storeInitiationResponse($payment, $response);

        return $payment->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshStatus(Payment $payment): Payment
    {
        $id = $payment->transaction_id ?: $payment->request_id;
        $response = $this->evpay->getPaymentStatus($id);
        $data = $this->extractTransactionData($response);

        $this->applyProviderUpdate($payment, $data);

        return $payment->fresh();
    }

    /**
     * Central status + fulfillment path used by webhooks and status polling.
     *
     * @param  array<string, mixed>  $data
     */
    public function applyProviderUpdate(Payment $payment, array $data, ?string $event = null): Payment
    {
        $shouldFulfill = false;
        $order = null;

        DB::transaction(function () use ($payment, $data, $event, &$shouldFulfill, &$order) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $status = $this->extractStatus($data);
            if ($status !== null) {
                $locked->status = $status;
            }

            $locked->transaction_id = $this->firstString($data, ['id', 'transactionId', 'transaction_id'])
                ?: $locked->transaction_id;
            $locked->fsp_reference = $this->firstString($data, ['fspReference', 'fsp_reference'])
                ?: $locked->fsp_reference;
            $locked->cbs_reference = $this->firstString($data, ['cbsReference', 'cbs_reference'])
                ?: $locked->cbs_reference;
            $locked->provider_message = $this->firstString($data, ['description', 'message', 'statusDescription'])
                ?: $locked->provider_message;
            $locked->provider_response = $data;

            if ($locked->status === Payment::STATUS_SETTLED && $locked->settled_at === null) {
                $locked->settled_at = now();
            }

            $alreadyFulfilled = $locked->isFulfilled();
            $qualifies = $locked->qualifiesForFulfillment();

            if ($qualifies && $locked->order_id) {
                $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->first();
                if ($order && $order->payment_status !== 'paid') {
                    $order->payment_gateway = Payment::PROVIDER_EVPAY;
                    $order->payment_status = 'paid';
                    $order->status = 'paid';
                    $order->paid_at = $order->paid_at ?: now();
                    $order->payment_reference = $locked->request_id;
                    $order->gateway_payment_id = $locked->transaction_id;
                    $order->save();
                }
            }

            if (in_array($locked->status, [Payment::STATUS_FAILED, Payment::STATUS_ON_HOLD], true)
                && $order === null
                && $locked->order_id
            ) {
                $related = Order::query()->whereKey($locked->order_id)->lockForUpdate()->first();
                if ($related && $related->payment_status !== 'paid') {
                    if ($locked->status === Payment::STATUS_FAILED) {
                        $related->payment_status = 'failed';
                        if ($related->status === 'pending_payment') {
                            $related->status = 'payment_failed';
                        }
                        $related->save();
                    }
                }
            }

            if ($qualifies && ! $alreadyFulfilled) {
                $shouldFulfill = true;
            }

            $locked->save();
            $payment->setRawAttributes($locked->getAttributes());
            $payment->syncOriginal();
        });

        if ($shouldFulfill) {
            $this->fulfillOnce($payment);
        } elseif (in_array($event, ['payment.refunded', 'payment.reversed'], true)) {
            Log::info('EVPay payment entered a reversal state; no automatic unwind', [
                'payment_id' => $payment->id,
                'request_id' => $payment->request_id,
                'status' => $payment->status,
                'event' => $event,
            ]);
        }

        return $payment->fresh();
    }

    public function processWebhookDelivery(int $deliveryId): void
    {
        $delivery = EvPayWebhookDelivery::query()->find($deliveryId);
        if (! $delivery) {
            Log::warning('EVPay webhook delivery missing for job', ['delivery_id' => $deliveryId]);

            return;
        }

        if ($delivery->isProcessed()) {
            return;
        }

        $event = $delivery->event;
        $payload = is_array($delivery->payload) ? $delivery->payload : [];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if (str_starts_with($event, 'payout.')) {
            Log::info('Ignoring EVPay payout webhook', [
                'delivery_id' => $delivery->delivery_id,
                'event' => $event,
            ]);
            $delivery->processed_at = now();
            $delivery->save();

            return;
        }

        if (! in_array($event, self::MOBILE_MONEY_EVENTS, true)) {
            Log::info('Ignoring unknown EVPay webhook event', [
                'delivery_id' => $delivery->delivery_id,
                'event' => $event,
            ]);
            $delivery->processed_at = now();
            $delivery->save();

            return;
        }

        $payment = $this->findPaymentFromWebhookData($data);
        if (! $payment) {
            Log::warning('EVPay webhook payment not found', [
                'delivery_id' => $delivery->delivery_id,
                'event' => $event,
                'order_reference' => $this->firstString($data, ['orderReference', 'requestId', 'request_id']),
                'transaction_id' => $this->firstString($data, ['id', 'transactionId']),
            ]);
            $delivery->processed_at = now();
            $delivery->save();

            return;
        }

        $delivery->payment_id = $payment->id;
        $delivery->save();

        $this->applyProviderUpdate($payment, $data, $event);

        $delivery->processed_at = now();
        $delivery->save();

        Log::info('EVPay webhook processed', [
            'delivery_id' => $delivery->delivery_id,
            'event' => $event,
            'payment_id' => $payment->id,
            'request_id' => $payment->request_id,
            'transaction_id' => $payment->transaction_id,
            'status' => $payment->fresh()?->status,
        ]);
    }

    public function assertAccessibleBy(Payment $payment, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if ((int) $payment->user_id !== (int) $user->id) {
            abort(response()->json([
                'success' => false,
                'message' => 'You do not have access to this payment.',
                'code' => 'forbidden',
            ], 403));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function publicPaymentPayload(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'request_id' => $payment->request_id,
            'transaction_id' => $payment->transaction_id,
            'order_id' => $payment->order_id,
            'operator' => $payment->operator,
            'phone_number' => $payment->phone_number,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'message' => $payment->provider_message,
            'fulfilled' => $payment->isFulfilled(),
        ];
    }

    private function fulfillOnce(Payment $payment): void
    {
        $order = null;
        $claimed = false;

        DB::transaction(function () use ($payment, &$order, &$claimed) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();
            if (! $locked || $locked->isFulfilled() || ! $locked->qualifiesForFulfillment()) {
                return;
            }

            if (! $locked->order_id) {
                $locked->fulfilled_at = now();
                $locked->save();

                return;
            }

            $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->first();
            $locked->fulfilled_at = now();
            $locked->save();
            $claimed = true;
        });

        if (! $claimed || ! $order) {
            return;
        }

        try {
            $this->simAssignment->fulfillPaidOrder($order, [
                'payment_id' => $payment->transaction_id,
                'transaction_reference' => $payment->request_id,
            ]);
        } catch (\Throwable $e) {
            Payment::query()->whereKey($payment->id)->update(['fulfilled_at' => null]);

            Log::error('Order fulfillment failed after EVPay success', [
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'request_id' => $payment->request_id,
                'transaction_id' => $payment->transaction_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findPaymentFromWebhookData(array $data): ?Payment
    {
        $orderReference = $this->firstString($data, ['orderReference', 'requestId', 'request_id']);
        if ($orderReference) {
            $payment = Payment::query()->where('request_id', $orderReference)->first();
            if ($payment) {
                return $payment;
            }
        }

        $transactionId = $this->firstString($data, ['id', 'transactionId', 'transaction_id']);
        if ($transactionId) {
            return Payment::query()->where('transaction_id', $transactionId)->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function storeInitiationResponse(Payment $payment, array $response): void
    {
        $data = $this->extractTransactionData($response);

        $payment->transaction_id = $this->firstString($data, ['transactionId', 'transaction_id', 'id'])
            ?: $payment->transaction_id;
        $status = $this->extractStatus($data) ?? Payment::STATUS_PENDING;
        if ($status === Payment::STATUS_SUCCESS || $status === Payment::STATUS_SETTLED) {
            $status = Payment::STATUS_PENDING;
        }
        $payment->status = $status;
        $payment->provider_message = $this->firstString($response, ['message'])
            ?: $this->firstString($data, ['description', 'message']);
        $payment->provider_response = $response;
        $payment->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildEvPayPayload(
        string $operator,
        int $amount,
        string $product,
        string $phone,
        string $requestId,
        ?string $payerName,
        ?string $narrative,
    ): array {
        $callback = trim((string) config('services.evpay.callback_url'));
        if ($callback === '') {
            $callback = rtrim((string) config('app.url'), '/').'/api/evpay/webhook';
        }

        $payload = [
            'fsOperator' => $operator,
            'amount' => $amount,
            'product' => $product,
            'callbackUrl' => $callback,
            'payerAccountId' => $phone,
            'requestId' => $requestId,
            'currency' => 'TZS',
        ];

        if (is_string($payerName) && $payerName !== '') {
            $payload['payerName'] = $payerName;
        }

        if (is_string($narrative) && $narrative !== '') {
            $payload['narrative'] = $narrative;
        }

        return $payload;
    }

    private function resolveRetryablePayment(
        User $user,
        ?string $requestId,
        ?Order $order,
        string $operator,
        string $phone,
        int $amount,
    ): ?Payment {
        if ($requestId) {
            $existing = Payment::query()->where('request_id', $requestId)->first();
            if (! $existing) {
                throw ValidationException::withMessages(['request_id' => 'Payment not found for retry.']);
            }
            $this->assertAccessibleBy($existing, $user);

            return $existing;
        }

        if (! $order) {
            return null;
        }

        return Payment::query()
            ->where('order_id', $order->id)
            ->where('user_id', $user->id)
            ->where('operator', $operator)
            ->where('phone_number', $phone)
            ->where('amount', $amount)
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING])
            ->latest('id')
            ->first();
    }

    private function resolveAmountTzs(?Order $order, mixed $requested): int
    {
        if ($order) {
            $currency = strtoupper((string) ($order->currency ?: 'TZS'));
            $total = (float) $order->total_amount;
            $tzs = match ($currency) {
                'TZS' => (int) round($total),
                'USD' => (int) round($total * (float) config('services.fx.tzs_to_usd_rate', 2610)),
                default => throw ValidationException::withMessages([
                    'amount' => 'Unsupported order currency for mobile money.',
                ]),
            };

            if ($requested !== null && $requested !== '') {
                if ((int) round((float) $requested) !== $tzs) {
                    throw ValidationException::withMessages([
                        'amount' => 'Amount does not match the order total.',
                    ]);
                }
            }

            return $tzs;
        }

        if ($requested === null || $requested === '') {
            throw ValidationException::withMessages(['amount' => 'Amount is required.']);
        }

        return (int) round((float) $requested);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function extractTransactionData(array $response): array
    {
        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractStatus(array $data): ?string
    {
        $status = $data['status'] ?? null;
        if (! is_string($status) || $status === '') {
            return null;
        }

        return strtoupper($status) === 'ON_HOLD' ? Payment::STATUS_ON_HOLD : strtoupper($status);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
