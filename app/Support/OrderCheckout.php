<?php

namespace App\Support;

use App\Models\Order;

class OrderCheckout
{
    public const MODE_TOPUP = 'topup';

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public static function isTopUp(?array $metadata): bool
    {
        return strtolower((string) ($metadata['checkoutMode'] ?? '')) === self::MODE_TOPUP;
    }

    public static function isTopUpOrder(Order $order): bool
    {
        $meta = $order->metadata;

        return self::isTopUp(is_array($meta) ? $meta : []);
    }
}
