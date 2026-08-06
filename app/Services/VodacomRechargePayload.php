<?php

namespace App\Services;

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
