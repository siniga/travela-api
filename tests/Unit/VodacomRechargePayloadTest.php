<?php

namespace Tests\Unit;

use App\Services\VodacomRechargePayload;
use Tests\TestCase;

class VodacomRechargePayloadTest extends TestCase
{
    public function test_normalize_matches_vodacom_documented_shape(): void
    {
        config(['services.vodacom_sim.recharge_reference_prefix' => 'RECHARGE']);

        $payload = VodacomRechargePayload::normalize([
            'msisdn' => '25583479408',
            'network_id' => 1,
            'product_id' => 66,
            'reference' => 'RECHARGE123',
        ]);

        $this->assertSame([
            'msisdn' => '25583479408',
            'network_id' => 1,
            'product_id' => 66,
            'reference' => 'RECHARGE123',
        ], $payload);
    }

    public function test_normalize_strips_plus_from_msisdn(): void
    {
        $payload = VodacomRechargePayload::normalize([
            'msisdn' => '+255768632087',
            'network_id' => 1,
            'product_id' => 66,
            'reference' => 'RECHARGE153335',
        ]);

        $this->assertSame('255768632087', $payload['msisdn']);
        $this->assertSame('RECHARGE153335', $payload['reference']);
        $this->assertArrayNotHasKey('airtime_amount', $payload);
    }

    public function test_normalize_includes_airtime_when_provided(): void
    {
        $payload = VodacomRechargePayload::normalize([
            'msisdn' => '255768632087',
            'network_id' => 1,
            'product_id' => 66,
            'reference' => 'RECHARGE153335',
            'airtime_amount' => 500,
        ]);

        $this->assertSame('500.00', $payload['airtime_amount']);
    }

    public function test_generate_reference_is_stable_for_same_order_item(): void
    {
        config(['services.vodacom_sim.recharge_reference_prefix' => 'RECHARGE']);

        $first = VodacomRechargePayload::generateReference(15, 3335);
        $second = VodacomRechargePayload::generateReference(15, 3335);

        $this->assertStringStartsWith('RECHARGE', $first);
        $this->assertSame($first, $second);
    }

    public function test_generate_reference_changes_when_retry_seed_differs(): void
    {
        config(['services.vodacom_sim.recharge_reference_prefix' => 'RECHARGE']);

        $first = VodacomRechargePayload::generateReference(78, 78, '78:78');
        $retry = VodacomRechargePayload::generateReference(78, 78, '78:78:retry');

        $this->assertStringStartsWith('RECHARGE', $first);
        $this->assertNotSame($first, $retry);
    }
}
