<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\BundleType;
use App\Models\Country;
use App\Models\CountryProvider;
use App\Models\Esim;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Provider;
use App\Models\User;
use App\Models\UserEsim;
use App\Services\SimAssignmentService;
use App\Services\VodacomSimManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SimAssignmentVodacomActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_assign_for_paid_order_activates_on_vodacom_before_recharge(): void
    {
        [$order, $user] = $this->createPaidEsimOrderFixture();

        $activateCalled = false;
        $rechargeCalled = false;

        $this->mock(VodacomSimManagerService::class, function ($mock) use (&$activateCalled, &$rechargeCalled) {
            $mock->shouldReceive('post')
                ->once()
                ->ordered()
                ->with('/api/sims-activate', \Mockery::type('array'))
                ->andReturnUsing(function () use (&$activateCalled) {
                    $activateCalled = true;

                    return Http::response(['status' => 'SUCCESS'], 200);
                });

            $mock->shouldReceive('post')
                ->once()
                ->ordered()
                ->with('/api/recharge', [], \Mockery::type('array'), \Mockery::any())
                ->andReturnUsing(function () use (&$activateCalled, &$rechargeCalled) {
                    $this->assertTrue($activateCalled, 'Recharge must run after Vodacom activation.');
                    $rechargeCalled = true;

                    return Http::response(['status' => 'SUCCESS', 'transaction_id' => 'tx-assign-1'], 200);
                });
        });

        $result = app(SimAssignmentService::class)->assignForPaidOrder($order);

        $this->assertTrue($result['assigned'] ?? false);
        $this->assertTrue($activateCalled);
        $this->assertTrue($rechargeCalled);
        $this->assertTrue($result['activation']['success'] ?? false);
        $this->assertSame('success', $order->fresh()->recharge_status);

        $assignment = UserEsim::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($assignment?->esim?->fresh()->vodacom_activated_at);
    }

    public function test_assign_for_paid_order_skips_recharge_when_activation_fails(): void
    {
        [$order] = $this->createPaidEsimOrderFixture();

        $this->mock(VodacomSimManagerService::class, function ($mock) {
            $mock->shouldReceive('post')
                ->once()
                ->with('/api/sims-activate', \Mockery::type('array'))
                ->andReturn(Http::response(['status' => 'FAILED', 'message' => 'Invalid SIM'], 400));

            $mock->shouldReceive('post')
                ->with('/api/recharge', \Mockery::any(), \Mockery::any(), \Mockery::any())
                ->never();
        });

        $result = app(SimAssignmentService::class)->assignForPaidOrder($order);

        $this->assertTrue($result['assigned'] ?? false);
        $this->assertFalse($result['activation']['success'] ?? true);
        $this->assertSame('pending_activation', $result['recharge']['recharge_status'] ?? null);
        $this->assertNull($order->fresh()->recharge_status);
    }

    /**
     * @return array{0: Order, 1: User}
     */
    private function createPaidEsimOrderFixture(): array
    {
        $user = User::factory()->create();

        $bundleType = BundleType::create(['code' => 'DATA', 'name' => 'Data only']);
        $country = Country::create(['name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA']);
        $provider = Provider::create(['name' => 'Vodacom', 'slug' => 'vodacom']);
        $pivot = CountryProvider::create([
            'country_id' => $country->id,
            'provider_id' => $provider->id,
            'is_default' => true,
        ]);

        $bundle = Bundle::create([
            'bundle_type_id' => $bundleType->id,
            'country_provider_id' => $pivot->id,
            'name' => 'Nomad 10GB',
            'validity_days' => 30,
            'price_usd' => 90.00,
            'price_tzs' => 500,
            'currency' => 'USD',
            'sim_bundle_id' => 66,
            'active' => true,
        ]);

        Esim::query()->create([
            'msisdn' => '255797053059',
            'iccid' => '8925500000000000201',
            'sim_type' => Esim::SIM_TYPE_ESIM,
            'status' => 'AVAILABLE',
            'sale_status' => Esim::SALE_STATUS_AVAILABLE,
            'provider_status' => Esim::PROVIDER_STATUS_ACTIVE,
            'network_id' => 1,
        ]);

        $order = Order::create([
            'draft_id' => 'DRAFT-ACTIVATE-'.uniqid(),
            'user_id' => $user->id,
            'status' => 'paid',
            'payment_status' => 'paid',
            'subtotal' => 90.00,
            'discount_amount' => 0,
            'total_amount' => 90.00,
            'currency' => 'USD',
            'paid_at' => now(),
            'metadata' => ['simType' => Esim::SIM_TYPE_ESIM],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'type' => 'bundle',
            'bundle_id' => $bundle->id,
            'bundle_name' => $bundle->name,
            'price' => 90.00,
            'currency' => 'USD',
        ]);

        $order->load('orderItems.bundle');

        return [$order, $user];
    }
}
