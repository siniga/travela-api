<?php

namespace Tests\Feature;

use App\Models\Esim;
use App\Models\User;
use App\Models\UserEsim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserEsimActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_fetch_qr_code_data_for_owned_esim(): void
    {
        $user = User::factory()->create();
        $esim = Esim::query()->create([
            'msisdn' => '255793045330',
            'iccid' => '8925500000000000001',
            'sim_type' => Esim::SIM_TYPE_ESIM,
            'status' => 'AVAILABLE',
            'sale_status' => Esim::SALE_STATUS_SOLD,
            'network_id' => 1,
            'qr_code_data' => 'LPA:1$smdp.example.com$ACTIVATION-CODE',
        ]);

        $assignment = UserEsim::query()->create([
            'user_id' => $user->id,
            'esim_id' => $esim->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/me/esims/{$assignment->id}/activation");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.qr_code_data', 'LPA:1$smdp.example.com$ACTIVATION-CODE')
            ->assertJsonPath('data.esim.msisdn', '255793045330')
            ->assertJsonPath('data.esim.iccid', '8925500000000000001')
            ->assertJsonMissingPath('data.lpa_string');
    }

    public function test_assignment_payload_includes_complete_esim_with_qr_code_data(): void
    {
        $user = User::factory()->create();
        $esim = Esim::query()->create([
            'msisdn' => '255793045334',
            'iccid' => '8925500000000000004',
            'sim_type' => Esim::SIM_TYPE_ESIM,
            'status' => 'MANAGED',
            'sale_status' => Esim::SALE_STATUS_SOLD,
            'network_id' => 1,
            'qr_code_data' => 'LPA:1$smdp.example.com$FROM-ASSIGNMENT',
        ]);

        UserEsim::query()->create([
            'user_id' => $user->id,
            'esim_id' => $esim->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/me/esims');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.esim.msisdn', '255793045334')
            ->assertJsonPath('data.0.esim.iccid', '8925500000000000004')
            ->assertJsonPath('data.0.esim.qr_code_data', 'LPA:1$smdp.example.com$FROM-ASSIGNMENT')
            ->assertJsonPath('data.0.esim.has_activation_data', true);
    }

    public function test_activation_returns_not_available_when_qr_code_data_missing(): void
    {
        $user = User::factory()->create();
        $esim = Esim::query()->create([
            'msisdn' => '255793045331',
            'sim_type' => Esim::SIM_TYPE_ESIM,
            'status' => 'AVAILABLE',
            'sale_status' => Esim::SALE_STATUS_SOLD,
            'network_id' => 1,
            'qr_code_data' => null,
        ]);

        $assignment = UserEsim::query()->create([
            'user_id' => $user->id,
            'esim_id' => $esim->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/me/esims/{$assignment->id}/activation");

        $response->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Activation data is not available for this eSIM.');
    }

    public function test_user_cannot_fetch_activation_for_another_users_esim(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $esim = Esim::query()->create([
            'msisdn' => '255793045332',
            'sim_type' => Esim::SIM_TYPE_ESIM,
            'status' => 'AVAILABLE',
            'sale_status' => Esim::SALE_STATUS_SOLD,
            'network_id' => 1,
            'qr_code_data' => 'LPA:1$smdp.example.com$SECRET',
        ]);

        $assignment = UserEsim::query()->create([
            'user_id' => $owner->id,
            'esim_id' => $esim->id,
        ]);

        Sanctum::actingAs($other);

        $response = $this->getJson("/api/me/esims/{$assignment->id}/activation");

        $response->assertForbidden();
    }
}
