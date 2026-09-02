<?php

namespace App\Services\Esim;

use App\Models\Esim;

/**
 * Normalizes JSON bodies for Vodacom POST /api/recharge.
 *
 * Expected shape:
 * {
 *   "msisdn": "25583479408",
 *   "network_id": 1,
 *   "product_id": 66,
 *   "reference": "RECHARGE123"
 * }
 */
class VodacomRechargePayload
{
    private const SUCCESS_STATUSES = ['success', 'successful', 'succeeded', 'completed', 'complete', 'approved'];

    private const FAILED_STATUSES = ['failed', 'failure', 'error', 'rejected', 'declined'];

    /**
     * Flatten Vodacom envelopes so `{ "data": { "status": "SUCCEEDED", ... } }` is readable at the top level.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function unwrapResponse(array $body): array
    {
        $data = $body['data'] ?? null;
        if (is_array($data) && $data !== [] && ! array_is_list($data)) {
            return array_merge($body, $data);
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function statusText(array $body): string
    {
        $unwrapped = self::unwrapResponse($body);

        return strtolower((string) ($unwrapped['status'] ?? $unwrapped['Status'] ?? $unwrapped['state'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function isSuccessStatus(array $body, int $httpStatus = 200): bool
    {
        if ($httpStatus < 200 || $httpStatus >= 300 || $httpStatus === 202) {
            return false;
        }

        if (in_array(self::statusText($body), self::SUCCESS_STATUSES, true)) {
            return true;
        }

        $unwrapped = self::unwrapResponse($body);

        return filter_var($unwrapped['success'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function isFailedStatus(array $body, int $httpStatus = 200): bool
    {
        if ($httpStatus >= 400) {
            return true;
        }

        if (in_array(self::statusText($body), self::FAILED_STATUSES, true)) {
            return true;
        }

        $unwrapped = self::unwrapResponse($body);

        return array_key_exists('success', $unwrapped)
            && filter_var($unwrapped['success'], FILTER_VALIDATE_BOOLEAN) === false;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function transactionIdFrom(array $body, bool $includeNumericId = true): ?string
    {
        $unwrapped = self::unwrapResponse($body);

        foreach (['transaction_id', 'transactionId', 'TransactionId'] as $key) {
            $value = $unwrapped[$key] ?? null;
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        if ($includeNumericId) {
            $value = $unwrapped['id'] ?? null;
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Vodacom RechargeResponse uses `price`; some callbacks use `amount`.
     *
     * @param  array<string, mixed>  $body
     */
    public static function amountFrom(array $body): mixed
    {
        $unwrapped = self::unwrapResponse($body);
        $value = $unwrapped['price'] ?? $unwrapped['amount'] ?? null;

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function interpretStatus(array $body, int $httpStatus): string
    {
        if ($httpStatus < 200 || $httpStatus >= 300) {
            return 'pending_retry';
        }

        if ($httpStatus === 202) {
            return 'queued';
        }

        $unwrapped = self::unwrapResponse($body);
        $statusText = self::statusText($unwrapped);
        $message = strtolower((string) ($unwrapped['message'] ?? $unwrapped['Message'] ?? ''));

        if (
            str_contains($message, 'queued')
            || str_contains($statusText, 'queued')
            || str_contains($message, 'callback')
        ) {
            return 'queued';
        }

        if (self::isSuccessStatus($unwrapped, $httpStatus)) {
            return 'success';
        }

        if (in_array($statusText, ['pending', 'processing', 'in_progress', 'submitted'], true)) {
            return 'pending';
        }

        if (str_contains($message, 'pending') || str_contains($message, 'processing')) {
            return 'pending';
        }

        if (self::isFailedStatus($unwrapped, $httpStatus)) {
            return 'failed';
        }

        return 'success';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string|int>
     */
    public static function normalize(array $payload): array
    {
        $normalized = [];

        if (isset($payload['airtime_amount']) && $payload['airtime_amount'] !== '' && $payload['airtime_amount'] !== null) {
            $normalized['airtime_amount'] = self::formatAirtimeAmount($payload['airtime_amount']);
        }

        if (! empty($payload['msisdn'])) {
            $normalized['msisdn'] = self::formatMsisdn((string) $payload['msisdn']);
        }

        if (isset($payload['network_id']) && $payload['network_id'] !== '') {
            $normalized['network_id'] = (int) $payload['network_id'];
        }

        if (isset($payload['product_id']) && $payload['product_id'] !== '') {
            $normalized['product_id'] = (int) $payload['product_id'];
        }

        if (! empty($payload['reference'])) {
            $normalized['reference'] = self::formatReference((string) $payload['reference']);
        }

        return $normalized;
    }

    public static function formatMsisdn(string $msisdn): string
    {
        return Esim::normalizeMsisdn($msisdn);
    }

    public static function formatAirtimeAmount(mixed $value): string
    {
        $numeric = is_string($value)
            ? (float) str_replace([',', ' '], '', trim($value))
            : (float) $value;

        return number_format(max(0.01, $numeric), 2, '.', '');
    }

    public static function formatReference(string $reference): string
    {
        return trim($reference);
    }

    /**
     * Unique numeric suffix after RECHARGE (e.g. RECHARGE153335).
     */
    public static function generateReference(int $orderId, int $orderItemId, ?string $seed = null): string
    {
        $prefix = (string) config('services.vodacom_sim.recharge_reference_prefix', 'RECHARGE');
        $basis = $seed ?? "{$orderId}:{$orderItemId}";
        $suffix = abs(crc32($basis)) % 1000000000;

        if ($suffix < 100) {
            $suffix += 100;
        }

        return $prefix.$suffix;
    }
}
