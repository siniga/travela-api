<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

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
        $returnUrl = rtrim((string) config('app.url'), '/') . '/payments/evpay/return';
        $callbackUrl = rtrim((string) config('app.url'), '/') . '/api/payments/evpay/callback';

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

