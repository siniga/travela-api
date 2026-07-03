<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\BundleType;
use App\Models\Country;
use App\Models\CountryProvider;
use App\Models\Esim;
use App\Models\Kyc;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Provider;
use App\Models\Trip;
use App\Models\User;
use App\Models\UserEsim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentOrderSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_finds_paid_physical_order_by_last_three_digits(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        Sanctum::actingAs($agent);

        $customer = User::factory()->create([
            'name' => 'Jane Traveler',
            'email' => 'jane@example.com',
        ]);

        $order = $this->createPhysicalPaidOrder($customer, 'DRAFT-2026-001');

        $response = $this->getJson('/api/agent/orders/search?order_suffix=001');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('match_mode', 'draft_id_suffix')
            ->assertJsonPath('count', 1)
            ->assertJsonPath('suggestions.0.draft_id', 'DRAFT-2026-001')
            ->assertJsonPath('suggestions.0.is_paid', true)
            ->assertJsonPath('suggestions.0.sim_type', Esim::SIM_TYPE_PHYSICAL)
            ->assertJsonPath('suggestions.0.has_sim_assignment', false)
            ->assertJsonPath('suggestions.0.user.name', 'Jane Traveler')
            ->assertJsonPath('suggestions.0.user.email', 'jane@example.com')
            ->assertJsonPath('suggestions.0.bundle.bundle_name', 'Starter 1GB')
            ->assertJsonPath('suggestions.0.trip.destination_country', 'KE')
            ->assertJsonPath('suggestions.0.kyc.nationality', 'Tanzanian');
    }

    public function test_agent_order_suffix_search_excludes_assigned_orders_by_default(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        Sanctum::actingAs($agent);

        $customer = User::factory()->create();
        $order = $this->createPhysicalPaidOrder($customer, 'DRAFT-2026-002');

        $esim = Esim::create([
            'msisdn' => '255798091399',
            'iccid' => '8925504500855150499',
            'sim_type' => Esim::SIM_TYPE_PHYSICAL,
            'status' => 'MANAGED',
            'provider_status' => Esim::PROVIDER_STATUS_ACTIVE,
        ]);

        UserEsim::create([
            'user_id' => $customer->id,
            'esim_id' => $esim->id,
            'order_id' => $order->id,
        ]);

        $response = $this->getJson('/api/agent/orders/search?order_suffix=002');

        $response->assertOk()
            ->assertJsonPath('count', 0);
    }

    public function test_agent_order_suffix_search_excludes_unpaid_orders(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        Sanctum::actingAs($agent);

        $customer = User::factory()->create();

        Order::create([
            'draft_id' => 'DRAFT-2026-003',
            'user_id' => $customer->id,
            'status' => 'pending_payment',
            'payment_status' => 'pending',
            'subtotal' => 25,
            'discount_amount' => 0,
            'total_amount' => 25,
            'currency' => 'USD',
            'metadata' => ['simType' => Esim::SIM_TYPE_PHYSICAL],
        ]);

        $response = $this->getJson('/api/agent/orders/search?order_suffix=003');

        $response->assertOk()
            ->assertJsonPath('count', 0);
    }

    public function test_agent_can_search_order_by_full_draft_id(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        Sanctum::actingAs($agent);

        $customer = User::factory()->create();
        $this->createPhysicalPaidOrder($customer, 'DRAFT-2026-004');

        $response = $this->getJson('/api/agent/orders/search?draft_id=DRAFT-2026-004');

        $response->assertOk()
            ->assertJsonPath('match_mode', 'exact')
            ->assertJsonPath('count', 1)
            ->assertJsonPath('suggestions.0.draft_id', 'DRAFT-2026-004');
    }

    public function test_physical_order_rejects_sim_fields_at_checkout(): void
    {
        $customer = User::factory()->create();
        Sanctum::actingAs($customer);

        $payload = $this->physicalOrderPayload($customer->id);
        $payload['msisdn'] = '255798091321';

        $response = $this->postJson('/api/orders', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['msisdn']);
    }

    public function test_physical_order_checkout_does_not_assign_sim(): void
    {
        $customer = User::factory()->create();
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/orders', $this->physicalOrderPayload($customer->id));

        $response->assertCreated()
            ->assertJsonPath('data.sim_assignment.assigned', false)
            ->assertJsonPath('data.sim_assignment.reason', 'physical_requires_agent');

        $this->assertDatabaseMissing('user_esims', [
            'user_id' => $customer->id,
        ]);
    }

    private function createPhysicalPaidOrder(User $customer, string $draftId): Order
    {
        $order = Order::create([
            'draft_id' => $draftId,
            'user_id' => $customer->id,
            'status' => 'paid',
            'payment_status' => 'paid',
            'subtotal' => 25,
            'discount_amount' => 0,
            'total_amount' => 25,
            'currency' => 'USD',
            'paid_at' => now(),
            'metadata' => ['simType' => Esim::SIM_TYPE_PHYSICAL],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'type' => 'bundle',
            'bundle_name' => 'Starter 1GB',
            'data_amount' => 1,
            'validity_days' => 30,
            'price' => 25,
            'currency' => 'USD',
        ]);

        Trip::create([
            'order_id' => $order->id,
            'destination_country' => 'KE',
            'arrival_date' => '2026-07-01',
            'departure_date' => '2026-07-10',
            'duration_days' => 9,
        ]);

        Kyc::create([
            'user_id' => $customer->id,
            'passport_id' => 'A1234567',
            'passport_country' => 'TZ',
            'nationality' => 'Tanzanian',
            'gender' => 'Female',
            'reason' => 'tourism',
            'arrival_date' => '2026-07-01',
            'departure_date' => '2026-07-10',
        ]);

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function physicalOrderPayload(int $userId): array
    {
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
            'name' => 'Starter 1GB',
            'validity_days' => 30,
            'price_usd' => 25.00,
            'price_tzs' => 500,
            'currency' => 'USD',
            'sim_bundle_id' => 66,
            'active' => true,
        ]);

        return [
            'draft_id' => 'DRAFT-TEST-PHYS-'.uniqid(),
            'user_id' => $userId,
            'simType' => 'physical',
            'country' => 'TZ',
            'trip' => [
                'destination_country' => 'KE',
                'arrival_date' => '2026-07-01',
                'departure_date' => '2026-07-10',
                'duration_days' => 9,
            ],
            'items' => [
                [
                    'type' => 'bundle',
                    'bundle_id' => $bundle->id,
                    'bundle_name' => 'Starter 1GB',
                    'data_amount' => 1,
                    'validity_days' => 30,
                    'price' => 25,
                    'currency' => 'USD',
                ],
            ],
            'pricing' => [
                'subtotal' => 25,
                'discount_amount' => 0,
                'total_amount' => 25,
                'currency' => 'USD',
            ],
            'kyc' => [
                'passport_id' => 'A1234567',
                'passport_country' => 'TZ',
                'nationality' => 'Tanzanian',
                'gender' => 'Female',
                'reason_for_travel' => 'tourism',
            ],
            'payment' => [
                'status' => 'paid',
                'reference' => 'TEST-PAY',
                'method' => 'test',
                'paid_at' => now()->toIso8601String(),
            ],
            'order_metadata' => [
                'source' => 'test',
                'platform' => 'test',
                'created_at' => now()->toIso8601String(),
            ],
        ];
    }
}
