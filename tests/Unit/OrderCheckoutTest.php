<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Support\OrderCheckout;
use Tests\TestCase;

class OrderCheckoutTest extends TestCase
{
    public function test_detects_topup_checkout_mode(): void
    {
        $this->assertTrue(OrderCheckout::isTopUp(['checkoutMode' => 'topup']));
        $this->assertTrue(OrderCheckout::isTopUp(['checkoutMode' => 'TOPUP']));
        $this->assertFalse(OrderCheckout::isTopUp(['checkoutMode' => 'checkout']));
        $this->assertFalse(OrderCheckout::isTopUp(null));
    }

    public function test_detects_topup_on_order_model(): void
    {
        $order = new Order(['metadata' => ['checkoutMode' => 'topup']]);
        $this->assertTrue(OrderCheckout::isTopUpOrder($order));
    }
}
