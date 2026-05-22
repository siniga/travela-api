<?php

namespace Tests\Unit;

use App\Services\VodacomRechargePayload;
use Tests\TestCase;

class VodacomRechargePayloadTest extends TestCase
{
    public function test_normalize_matches_vodacom_example_shape(): void
    {
        config([
            'services.vodacom_sim.recharge_airtime_pad_width' => 5,
            'services.vodacom_sim.recharge_reference_prefix' => 'RECHARGE',
        ]);

        $payload = VodacomRechargePayload::normalize([
            'msisdn' => '255768632087',
            'network_id' => 1,
            'product_id' => 66,
            'reference' => 'RECHARGE153335',
            'airtime_amount' => 500,
        ]);

        $this->assertSame([
            'msisdn' => '255768632087',
            'network_id' => 1,
            'product_id' => 66,
            'reference' => 'RECHARGE153335',
            'airtime_amount' => '  500',
        ], $payload);
    }

    public function test_generate_reference_for_order_and_item(): void
    {
        config(['services.vodacom_sim.recharge_reference_prefix' => 'RECHARGE']);

        $this->assertSame('RECHARGE153335', VodacomRechargePayload::generateReference(15, 3335));
    }
}
