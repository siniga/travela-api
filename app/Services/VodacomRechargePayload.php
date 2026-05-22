<?php

namespace App\Services;

/**
 * Normalizes JSON bodies for Vodacom POST /api/recharge.
 *
 * @see https://simmanager.vodacom.co.tz — expected shape:
 * msisdn, network_id, product_id, reference (e.g. RECHARGE153335), airtime_amount (e.g. "  500")
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

        if (! empty($payload['msisdn'])) {
            $normalized['msisdn'] = (string) $payload['msisdn'];
        }

        if (isset($payload['network_id']) && $payload['network_id'] !== '') {
            $normalized['network_id'] = (int) $payload['network_id'];
        }

        if (isset($payload['product_id']) && $payload['product_id'] !== '') {
            $normalized['product_id'] = (int) $payload['product_id'];
        }

        if (! empty($payload['reference'])) {
            $normalized['reference'] = (string) $payload['reference'];
        }

        if (isset($payload['airtime_amount']) && $payload['airtime_amount'] !== '' && $payload['airtime_amount'] !== null) {
            $normalized['airtime_amount'] = self::formatAirtimeAmount($payload['airtime_amount']);
        }

        return $normalized;
    }

    public static function formatAirtimeAmount(mixed $value): string
    {
        if (is_string($value) && preg_match('/^\d+$/', trim($value))) {
            $digits = trim($value);
        } else {
            $digits = (string) max(1, (int) round((float) $value));
        }

        $width = max(strlen($digits), (int) config('services.vodacom_sim.recharge_airtime_pad_width', 5));

        return str_pad($digits, $width, ' ', STR_PAD_LEFT);
    }

    public static function generateReference(int $orderId, int $orderItemId): string
    {
        $prefix = (string) config('services.vodacom_sim.recharge_reference_prefix', 'RECHARGE');

        return $prefix.($orderId * 10000 + $orderItemId);
    }
}
