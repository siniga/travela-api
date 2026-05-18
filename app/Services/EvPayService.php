<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EvPayService
{
    public function prepare(Order $order): Order
    {
        if ($order->payment_status === 'paid') {
            return $order;
        }

        if ($this->shouldRegenerateReference($order->payment_reference)) {
            $order->payment_reference = $this->generateUniquePaymentReference();
        }

        $order->payment_gateway = 'evpay';
        $order->payment_status = 'pending';

        if ($order->status !== 'paid') {
            $order->status = 'pending_payment';
        }

        $order->save();

        return $order;
    }

    /**
     * @return array{order_id:int,payment_reference:string,checkout_url:string,payload:array<string,mixed>}
     */
    public function createCheckoutUrl(Order $order): array
    {
        $order->loadMissing('user');

        if ($order->payment_status === 'paid') {
            throw new \RuntimeException('This order is already paid.');
        }

        if ($this->shouldRegenerateReference($order->payment_reference)) {
            $order->payment_reference = $this->generateUniquePaymentReference();
            $order->payment_gateway = 'evpay';
            $order->payment_status = 'pending';
            $order->save();
        }

        $merchantId = trim((string) config('services.evpay.merchant_id'));
        $secretKey = trim((string) config('services.evpay.secret_key'));
        $checkoutBaseUrl = rtrim((string) config('services.evpay.checkout_url'), '/');

        if (! $merchantId || ! $secretKey || ! $checkoutBaseUrl) {
            throw new \RuntimeException('EvPay configuration is incomplete.');
        }

        $user = $order->user;
        $returnUrl = rtrim((string) config('services.evpay.return_url'), '/');
        $callbackUrl = config('services.evpay.callback_url')
            ?: rtrim((string) config('app.url'), '/') . '/api/payments/evpay/callback';

        $payload = [
            'total' => number_format((float) $order->total_amount, 2, '.', ''),
            'currency' => $order->currency ?: 'TZS',
            'reference' => (string) $order->payment_reference,
            'country' => 'TZ',
            'firstName' => $user?->first_name ?? 'Customer',
            'lastName' => $user?->last_name ?? 'User',
            'email' => $user?->email ?? 'customer@example.com',
            'phoneNumber' => preg_replace('/\D+/', '', $user?->phone ?? '255700000000'),
            'address' => $user?->address ?? 'Dar es Salaam',
            'postalCode' => $user?->postal_code ?? '00000',
            'returnUrl' => $returnUrl,
            'callbackUrl' => $callbackUrl,
        ];

        $jsonPayload = json_encode($payload);
        if ($jsonPayload === false) {
            throw new \RuntimeException('Failed to encode payment payload.');
        }

        $base64Payload = base64_encode($jsonPayload);
        $signature = hash_hmac('sha256', $base64Payload, $secretKey);

        $checkoutUrl = $checkoutBaseUrl . '/' . $merchantId
            . '?data=' . urlencode($base64Payload)
            . '&sig=' . $signature;

        $order->payment_payload = $payload;
        $order->payment_gateway = 'evpay';
        $order->payment_status = 'pending';
        $order->save();

        return [
            'order_id' => (int) $order->id,
            'payment_reference' => (string) $order->payment_reference,
            'checkout_url' => $checkoutUrl,
            'payload' => $payload,
        ];
    }

    /**
     * Process EvPay server callback (POST). Logs full request and updates the order when paid.
     *
     * @return array{success:bool,message:string,order_id?:int,payment_reference?:string,payment_status?:string}
     */
    public function handleCallback(Request $request): array
    {
        $rawLog = [
            'method' => $request->method(),
            'ip' => $request->ip(),
            'query' => $request->query(),
            'body' => $request->all(),
            'content' => $request->getContent(),
            'headers' => $request->headers->all(),
        ];

        Log::info('EvPay callback received', $rawLog);

        try {
            ['payload' => $payload, 'verified' => $verified] = $this->parseCallbackPayload($request);
        } catch (\InvalidArgumentException $e) {
            Log::warning('EvPay callback rejected', ['error' => $e->getMessage(), 'raw' => $rawLog]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }

        Log::info('EvPay callback parsed payload', [
            'verified' => $verified,
            'payload' => $payload,
        ]);

        $reference = $this->extractCallbackReference($payload);
        if (! $reference) {
            Log::warning('EvPay callback missing reference', ['payload' => $payload]);

            return [
                'success' => false,
                'message' => 'Missing payment reference in callback.',
            ];
        }

        $order = Order::where('payment_reference', $reference)->first();
        if (! $order) {
            Log::warning('EvPay callback order not found', ['reference' => $reference, 'payload' => $payload]);

            return [
                'success' => false,
                'message' => 'Order not found for reference: ' . $reference,
            ];
        }

        $callbackRecord = [
            'received_at' => now()->toIso8601String(),
            'verified' => $verified,
            'payload' => $payload,
            'raw' => [
                'query' => $request->query(),
                'body' => $request->all(),
                'content' => $request->getContent(),
            ],
        ];

        if ($order->payment_status === 'paid') {
            $order->payment_callback = array_merge(
                is_array($order->payment_callback) ? $order->payment_callback : [],
                ['duplicate' => $callbackRecord]
            );
            $order->save();

            Log::info('EvPay callback duplicate (already paid)', [
                'order_id' => $order->id,
                'reference' => $reference,
            ]);

            return [
                'success' => true,
                'message' => 'Order already paid.',
                'order_id' => $order->id,
                'payment_reference' => $order->payment_reference,
                'payment_status' => $order->payment_status,
            ];
        }

        $isPaid = $this->callbackIndicatesPaid($payload);
        $isFailed = $this->callbackIndicatesFailed($payload);

        DB::transaction(function () use ($order, $payload, $callbackRecord, $isPaid, $isFailed) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $order->payment_gateway = 'evpay';
            $order->payment_callback = $callbackRecord;

            if ($isPaid) {
                $order->payment_status = 'paid';
                $order->status = 'paid';
                $order->paid_at = now();
                $order->gateway_payment_id = $this->extractGatewayPaymentId($payload)
                    ?? $order->gateway_payment_id;
            } elseif ($isFailed) {
                $order->payment_status = 'failed';
                if ($order->status === 'pending_payment') {
                    $order->status = 'payment_failed';
                }
            }

            $order->save();
        });

        $order->refresh();

        Log::info('EvPay callback processed', [
            'order_id' => $order->id,
            'reference' => $reference,
            'payment_status' => $order->payment_status,
            'is_paid' => $isPaid,
            'is_failed' => $isFailed,
        ]);

        return [
            'success' => true,
            'message' => $isPaid ? 'Payment recorded.' : ($isFailed ? 'Payment failure recorded.' : 'Callback logged.'),
            'order_id' => $order->id,
            'payment_reference' => $order->payment_reference,
            'payment_status' => $order->payment_status,
        ];
    }

    /**
     * @return array{payload: array<string, mixed>, verified: bool}
     */
    private function parseCallbackPayload(Request $request): array
    {
        $data = $request->input('data') ?? $request->query('data');
        $sig = $request->input('sig') ?? $request->query('sig');

        if (is_string($data) && $data !== '') {
            $secretKey = trim((string) config('services.evpay.secret_key'));

            if (is_string($sig) && $sig !== '') {
                if (! $secretKey) {
                    throw new \InvalidArgumentException('EvPay secret key is not configured.');
                }

                $expected = hash_hmac('sha256', $data, $secretKey);
                if (! hash_equals($expected, $sig)) {
                    throw new \InvalidArgumentException('Invalid EvPay callback signature.');
                }
            }

            $decoded = base64_decode($data, true);
            if ($decoded === false) {
                throw new \InvalidArgumentException('Invalid EvPay callback data encoding.');
            }

            $payload = json_decode($decoded, true);
            if (! is_array($payload)) {
                throw new \InvalidArgumentException('Invalid EvPay callback JSON payload.');
            }

            return [
                'payload' => $payload,
                'verified' => is_string($sig) && $sig !== '',
            ];
        }

        $body = $request->all();
        if ($body !== []) {
            return [
                'payload' => $body,
                'verified' => false,
            ];
        }

        $content = $request->getContent();
        if ($content !== '' && $content !== false) {
            $payload = json_decode($content, true);
            if (is_array($payload)) {
                return [
                    'payload' => $payload,
                    'verified' => false,
                ];
            }
        }

        throw new \InvalidArgumentException('Empty EvPay callback payload.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractCallbackReference(array $payload): ?string
    {
        foreach (['reference', 'payment_reference', 'orderReference', 'order_reference', 'merchantReference'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractGatewayPaymentId(array $payload): ?string
    {
        foreach (['transaction_id', 'transactionId', 'payment_id', 'paymentId', 'gateway_payment_id', 'id'] as $key) {
            $value = $payload[$key] ?? null;
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function callbackIndicatesPaid(array $payload): bool
    {
        $status = strtolower((string) (
            $payload['status']
            ?? $payload['paymentStatus']
            ?? $payload['payment_status']
            ?? $payload['result']
            ?? ''
        ));

        if (in_array($status, ['paid', 'success', 'successful', 'completed', 'complete', 'approved'], true)) {
            return true;
        }

        $code = $payload['code'] ?? $payload['resultCode'] ?? $payload['responseCode'] ?? null;
        if ($code === 0 || $code === '0' || $code === '00') {
            return true;
        }

        if (filter_var($payload['paid'] ?? $payload['isPaid'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function callbackIndicatesFailed(array $payload): bool
    {
        $status = strtolower((string) (
            $payload['status']
            ?? $payload['paymentStatus']
            ?? $payload['payment_status']
            ?? $payload['result']
            ?? ''
        ));

        return in_array($status, ['failed', 'failure', 'cancelled', 'canceled', 'declined', 'error'], true);
    }

    private function shouldRegenerateReference(?string $reference): bool
    {
        if (! $reference) {
            return true;
        }

        if (strlen($reference) > 20) {
            return true;
        }

        return ! preg_match('/^ORD-\d{8}-\d{3}$/', $reference);
    }

    private function generateUniquePaymentReference(): string
    {
        return DB::transaction(function () {
            $date = now()->format('Ymd');
            $today = now()->toDateString();
            $prefix = "ORD-{$date}-";

            $latestToday = Order::whereDate('created_at', $today)
                ->whereNotNull('payment_reference')
                ->where('payment_reference', 'like', $prefix . '%')
                ->lockForUpdate()
                ->orderByDesc('payment_reference')
                ->value('payment_reference');

            $nextNumber = 1;
            if ($latestToday) {
                $lastPart = substr($latestToday, -3);
                if (is_numeric($lastPart)) {
                    $nextNumber = ((int) $lastPart) + 1;
                }
            }

            while ($nextNumber <= 999) {
                $reference = $prefix . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);

                if (strlen($reference) > 20) {
                    break;
                }

                $exists = Order::where('payment_reference', $reference)->exists();
                if (! $exists) {
                    return $reference;
                }

                $nextNumber++;
            }

            throw new \RuntimeException('Unable to generate a unique payment reference.');
        });
    }
}

