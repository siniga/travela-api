<?php

namespace Tests\Unit;

use App\Models\Esim;
use App\Services\VodacomSimActivationService;
use App\Services\VodacomSimManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VodacomSimActivationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_activate_marks_esim_success_on_vodacom_response(): void
    {
        Http::fake([
            'simmanager.vodacom.co.tz/api/sims-activate*' => Http::response(['status' => 'SUCCESS'], 200),
        ]);

        $esim = Esim::create([
            'msisdn' => '255797053059',
            'iccid' => '8933101234567890123',
            'network_id' => 1,
            'sim_type' => Esim::SIM_TYPE_ESIM,
            'status' => 'AVAILABLE',
            'provider_status' => Esim::PROVIDER_STATUS_SUSPENDED,
            'activation_status' => Esim::ACTIVATION_STATUS_PENDING,
        ]);

        $activated = app(VodacomSimActivationService::class)->activate($esim);

        $this->assertSame(Esim::ACTIVATION_STATUS_SUCCESS, $activated->activation_status);
        $this->assertNotNull($activated->vodacom_activated_at);
        $this->assertSame(Esim::PROVIDER_STATUS_ACTIVE, $activated->provider_status);
    }

    public function test_activate_is_idempotent_when_already_activated(): void
    {
        Http::fake();

        $esim = Esim::create([
            'msisdn' => '255797053060',
            'network_id' => 1,
            'sim_type' => Esim::SIM_TYPE_ESIM,
            'status' => 'AVAILABLE',
            'provider_status' => Esim::PROVIDER_STATUS_ACTIVE,
            'activation_status' => Esim::ACTIVATION_STATUS_SUCCESS,
            'vodacom_activated_at' => now()->subDay(),
        ]);

        $result = app(VodacomSimActivationService::class)->activate($esim);

        Http::assertNothingSent();
        $this->assertTrue($result->vodacom_activated_at->equalTo($esim->vodacom_activated_at));
    }

    public function test_activate_failure_marks_esim_failed_and_throws(): void
    {
        Http::fake([
            'simmanager.vodacom.co.tz/api/sims-activate*' => Http::response([
                'status' => 'FAILED',
                'message' => 'Invalid SIM',
            ], 400),
        ]);

        $esim = Esim::create([
            'msisdn' => '255797053061',
            'network_id' => 1,
            'sim_type' => Esim::SIM_TYPE_ESIM,
            'status' => 'AVAILABLE',
            'provider_status' => Esim::PROVIDER_STATUS_SUSPENDED,
            'activation_status' => Esim::ACTIVATION_STATUS_PENDING,
        ]);

        try {
            app(VodacomSimActivationService::class)->activate($esim);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Vodacom SIM activation failed', $e->getMessage());
        }

        $esim->refresh();
        $this->assertSame(Esim::ACTIVATION_STATUS_FAILED, $esim->activation_status);
        $this->assertSame(Esim::PROVIDER_STATUS_SUSPENDED, $esim->provider_status);
        $this->assertNotNull($esim->activation_error);
    }

    public function test_build_activate_query_requires_identifier(): void
    {
        $esim = Esim::create([
            'msisdn' => '255797053062',
            'network_id' => 1,
            'sim_type' => Esim::SIM_TYPE_ESIM,
            'status' => 'AVAILABLE',
        ]);

        $query = app(VodacomSimActivationService::class)->buildActivateQuery($esim);

        $this->assertSame('+255797053062', $query['msisdn']);
    }
}
