<?php

namespace Tests\Feature;

use App\Models\Esim;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\UserEsim;
use App\Services\Esim\VodacomBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VodacomRechargeCallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_callback_accepts_vodacom_recharge_response_with_price(): void
    {
        [$assignment, $order] = $this->seedAssignedSimAndOrder('RECHARGE-PRICE');
        $this->mockBalanceRefresh();

        $payload = [
            'created' => '2023-01-01T09:00:00Z',
            'failure_type' => null,
            'id' => 100,
            'msisdn' => '+255797053059',
            'price' => 25.0,
            'product_id' => 124,
            'product_type' => 'DATA',
            'reference' => 'RECHARGE-PRICE',
            'status' => 'SUCCEEDED',
            'succeeded_at' => '2023-01-01T10:00:00Z',
            'transaction_id' => 'd1b81f4b-c506-4a37-9275-6a9a82edff23',
        ];

        $response = $this->postJson('/api/public/my-recharge-callback-url', $payload);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount', 25)
            ->assertJsonPath('data.status', 'SUCCEEDED')
            ->assertJsonPath('data.reference', 'RECHARGE-PRICE')
            ->assertJsonPath('data.transaction_id', 'd1b81f4b-c506-4a37-9275-6a9a82edff23')
            ->assertJsonPath('data.assignment_updated', true)
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('callback.price', 25);

        $assignment->refresh();
        $order->refresh();
        $this->assertSame('25.00', (string) $assignment->last_recharge_amount);
        $this->assertSame('SUCCEEDED', $assignment->last_recharge_status);
        $this->assertSame('success', $order->recharge_status);
        $this->assertSame('d1b81f4b-c506-4a37-9275-6a9a82edff23', $order->recharge_transaction_id);
        $this->assertNotNull($order->metadata['recharge_callback'] ?? null);
    }

    public function test_callback_accepts_nested_data_envelope(): void
    {
        [$assignment] = $this->seedAssignedSimAndOrder('ORDER-20260730-0001');
        $this->mockBalanceRefresh();

        $response = $this->postJson('/api/public/my-recharge-callback-url', [
            'data' => [
                'id' => 2942535,
                'status' => 'SUCCESS',
                'reference' => 'ORDER-20260730-0001',
                'price' => '500.00',
                'product_type' => 'DATA',
                'failure_type' => '',
                'msisdn' => '+255797053059',
                'product_id' => 66,
                'transaction_id' => '81835876014717703343',
                'product_label' => 'Internet - 30Days - 25MB',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount', '500.00')
            ->assertJsonPath('data.status', 'SUCCESS')
            ->assertJsonPath('data.reference', 'ORDER-20260730-0001')
            ->assertJsonPath('callback.status', 'SUCCESS');

        $assignment->refresh();
        $this->assertSame('500.00', (string) $assignment->last_recharge_amount);
        $this->assertSame('ORDER-20260730-0001', $assignment->last_recharge_reference);
    }

    public function test_callback_rejects_missing_msisdn(): void
    {
        $response = $this->postJson('/api/public/my-recharge-callback-url', [
            'price' => 25,
            'status' => 'SUCCEEDED',
        ]);

        $response->assertStatus(422);
    }

    /**
     * @return array{0: UserEsim, 1: Order}
     */
    private function seedAssignedSimAndOrder(string $reference): array
    {
        $user = User::factory()->create();
        $esim = Esim::create([
            'msisdn' => '255797053059',
            'network_id' => 1,
            'status' => 'MANAGED',
        ]);
        $assignment = UserEsim::create([
            'user_id' => $user->id,
            'esim_id' => $esim->id,
        ]);

        $order = Order::create([
            'draft_id' => 'DRAFT-CB-'.uniqid(),
            'user_id' => $user->id,
            'status' => 'paid',
            'payment_status' => 'paid',
            'subtotal' => 90.00,
            'discount_amount' => 0,
            'total_amount' => 90.00,
            'currency' => 'USD',
            'paid_at' => now(),
            'recharge_status' => 'queued',
            'recharge_reference' => $reference,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'type' => 'bundle',
            'bundle_name' => 'Nomad 10GB',
            'price' => 90.00,
            'currency' => 'USD',
            'metadata' => [
                'recharge' => [
                    'reference' => $reference,
                    'status' => 'queued',
                ],
            ],
        ]);

        return [$assignment, $order];
    }

    private function mockBalanceRefresh(): void
    {
        $this->mock(VodacomBalanceService::class, function ($mock) {
            $mock->shouldReceive('requestBalancesForMsisdn')
                ->zeroOrMoreTimes()
                ->andReturn(['status' => 'queued', 'http_status' => 202]);
        });
    }
}
