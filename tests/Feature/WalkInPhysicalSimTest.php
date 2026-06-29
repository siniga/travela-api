<?php

namespace Tests\Feature;

use App\Mail\WalkInCustomerLoginMail;
use App\Models\Esim;
use App\Models\User;
use App\Models\UserEsim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalkInPhysicalSimTest extends TestCase
{
    use RefreshDatabase;

    public function test_iccid_search_returns_only_unassigned_physical_sims_when_available_only(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        Sanctum::actingAs($agent);

        $available = Esim::create([
            'msisdn' => '255798091321',
            'iccid' => '8925504500911766103',
            'sim_type' => Esim::SIM_TYPE_PHYSICAL,
            'status' => 'AVAILABLE',
            'sale_status' => Esim::SALE_STATUS_AVAILABLE,
            'provider_status' => Esim::PROVIDER_STATUS_ACTIVE,
        ]);

        $assigned = Esim::create([
            'msisdn' => '255798091322',
            'iccid' => '8925504500911766188',
            'sim_type' => Esim::SIM_TYPE_PHYSICAL,
            'status' => 'MANAGED',
            'sale_status' => Esim::SALE_STATUS_SOLD,
            'provider_status' => Esim::PROVIDER_STATUS_ACTIVE,
        ]);

        UserEsim::create([
            'user_id' => User::factory()->create()->id,
            'esim_id' => $assigned->id,
        ]);

        $esim = Esim::create([
            'msisdn' => '255798091323',
            'iccid' => '8925504500911766199',
            'sim_type' => Esim::SIM_TYPE_ESIM,
            'status' => 'AVAILABLE',
            'sale_status' => Esim::SALE_STATUS_AVAILABLE,
            'provider_status' => Esim::PROVIDER_STATUS_ACTIVE,
        ]);

        $response = $this->getJson('/api/agent/esims/search?iccid=03&sim_type=physical&available_only=1');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('suggestions.0.id', $available->id)
            ->assertJsonPath('suggestions.0.is_assigned', false)
            ->assertJsonPath('suggestions.0.sim_type', Esim::SIM_TYPE_PHYSICAL)
            ->assertJsonPath('suggestions.0.status', 'available')
            ->assertJsonPath('suggestions.0.label', '…03');

        $ids = collect($response->json('suggestions'))->pluck('id');
        $this->assertFalse($ids->contains($assigned->id));
        $this->assertFalse($ids->contains($esim->id));
    }

    public function test_walk_in_assign_creates_user_and_assigns_sim(): void
    {
        Mail::fake();

        $agent = User::factory()->create(['role' => 'agent']);
        Sanctum::actingAs($agent);

        $esim = Esim::create([
            'msisdn' => '255798091321',
            'iccid' => '8925504500911766103',
            'sim_type' => Esim::SIM_TYPE_PHYSICAL,
            'status' => 'AVAILABLE',
            'sale_status' => Esim::SALE_STATUS_AVAILABLE,
            'provider_status' => Esim::PROVIDER_STATUS_ACTIVE,
        ]);

        $response = $this->postJson('/api/agent/physical-sims/assign', [
            'esim_id' => $esim->id,
            'iccid' => $esim->iccid,
            'msisdn' => $esim->msisdn,
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'location' => 'Zanzibar Airport',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.esim_id', $esim->id)
            ->assertJsonPath('data.msisdn', $esim->msisdn)
            ->assertJsonPath('data.iccid', $esim->iccid)
            ->assertJsonPath('data.email_sent', true);

        $user = User::where('email', 'jane@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('Jane Doe', $user->name);
        $this->assertSame('user', $user->role);
        $this->assertNotNull($user->email_verified_at);

        $assignment = UserEsim::where('esim_id', $esim->id)->first();
        $this->assertNotNull($assignment);
        $this->assertSame($user->id, $assignment->user_id);
        $this->assertSame($agent->id, $assignment->physical_issued_by);
        $this->assertSame('Zanzibar Airport', $assignment->physical_issued_location);
        $this->assertNotNull($assignment->physical_issued_at);

        $esim->refresh();
        $this->assertSame('MANAGED', $esim->status);
        $this->assertSame(Esim::SALE_STATUS_SOLD, $esim->sale_status);

        Mail::assertSent(WalkInCustomerLoginMail::class, function (WalkInCustomerLoginMail $mail) use ($user) {
            return $mail->hasTo($user->email)
                && $mail->customerName === 'Jane Doe'
                && strlen($mail->code) === 6;
        });
    }

    public function test_walk_in_assign_rejects_already_assigned_sim(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        Sanctum::actingAs($agent);

        $customer = User::factory()->create();
        $esim = Esim::create([
            'msisdn' => '255798091321',
            'iccid' => '8925504500911766103',
            'sim_type' => Esim::SIM_TYPE_PHYSICAL,
            'status' => 'MANAGED',
            'sale_status' => Esim::SALE_STATUS_SOLD,
            'provider_status' => Esim::PROVIDER_STATUS_ACTIVE,
        ]);

        UserEsim::create([
            'user_id' => $customer->id,
            'esim_id' => $esim->id,
        ]);

        $response = $this->postJson('/api/agent/physical-sims/assign', [
            'esim_id' => $esim->id,
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_walk_in_assign_rejects_non_agent(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        Sanctum::actingAs($user);

        $esim = Esim::create([
            'msisdn' => '255798091321',
            'iccid' => '8925504500911766103',
            'sim_type' => Esim::SIM_TYPE_PHYSICAL,
            'status' => 'AVAILABLE',
            'sale_status' => Esim::SALE_STATUS_AVAILABLE,
            'provider_status' => Esim::PROVIDER_STATUS_ACTIVE,
        ]);

        $this->postJson('/api/agent/physical-sims/assign', [
            'esim_id' => $esim->id,
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
        ])->assertForbidden();
    }
}
