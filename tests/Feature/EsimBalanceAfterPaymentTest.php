<?php

namespace Tests\Feature;

use App\Models\Esim;
use App\Models\User;
use App\Models\UserEsim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EsimBalanceAfterPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected bool $forceMysqlTesting = true;

    public function test_wrapped_sims_balances_callback_updates_assignment(): void
    {
        $user = User::factory()->create();
        $esim = $this->createEsim('255793045340');
        $assignment = UserEsim::query()->create([
            'user_id' => $user->id,
            'esim_id' => $esim->id,
        ]);

        $response = $this->postJson('/api/public/sims-balances/callback', [
            'data' => [
                'msisdn' => '255793045340',
                'balances' => [
                    'DATA' => 1024,
                    'AIRTIME' => 0,
                    'SMS' => 0,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('esim_id', $esim->id);

        $assignment->refresh();
        $esim->refresh();

        $this->assertSame(1024.0, (float) $assignment->balances['DATA']);
        $this->assertSame(1024.0, (float) $esim->balances['DATA']);
        $this->assertNotNull($assignment->balance_fetched_at);
    }

    public function test_balance_status_is_ready_when_only_airtime_is_fresh(): void
    {
        $user = User::factory()->create();
        $esim = $this->createEsim('255793045341');
        UserEsim::query()->create([
            'user_id' => $user->id,
            'esim_id' => $esim->id,
            'balances' => ['AIRTIME' => 500, 'DATA' => null, 'SMS' => null],
            'balance_fetched_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/me/esims/balance-status?since='.urlencode(now()->subMinute()->toIso8601String()))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('balance_ready', true)
            ->assertJsonPath('poll_again', false);
    }

    public function test_me_esims_includes_live_balances_on_nested_esim(): void
    {
        $user = User::factory()->create();
        $esim = $this->createEsim('255793045342', [
            'balances' => ['DATA' => 650, 'AIRTIME' => 0, 'SMS' => 0],
            'balance_fetched_at' => now(),
        ]);
        UserEsim::query()->create([
            'user_id' => $user->id,
            'esim_id' => $esim->id,
            'balances' => ['DATA' => 650, 'AIRTIME' => 0, 'SMS' => 0],
            'balance_fetched_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/me/esims')
            ->assertOk()
            ->assertJsonPath('data.0.balances.DATA', 650)
            ->assertJsonPath('data.0.esim.balances.DATA', 650);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function createEsim(string $msisdn, array $extra = []): Esim
    {
        return Esim::query()->create(array_merge([
            'msisdn' => $msisdn,
            'iccid' => '892550000000'.substr(preg_replace('/\D/', '', $msisdn) ?? $msisdn, -6),
            'sim_type' => Esim::SIM_TYPE_ESIM,
            'status' => 'MANAGED',
            'sale_status' => Esim::SALE_STATUS_SOLD,
            'network_id' => 1,
        ], $extra));
    }
}
