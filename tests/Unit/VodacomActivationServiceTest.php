<?php

namespace Tests\Unit;

use App\Models\Esim;
use App\Services\VodacomActivationService;
use App\Services\VodacomSimManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VodacomActivationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_activate_if_needed_calls_vodacom_and_marks_esim_activated(): void
    {
        $esim = Esim::query()->create([
            'msisdn' => '255793045401',
            'iccid' => '8925500000000000101',
            'sim_type' => Esim::SIM_TYPE_ESIM,
            'status' => 'AVAILABLE',
            'provider_status' => Esim::PROVIDER_STATUS_ACTIVE,
            'network_id' => 1,
        ]);

        $this->mock(VodacomSimManagerService::class, function ($mock) {
            $mock->shouldReceive('post')
                ->once()
                ->with('/api/sims-activate', \Mockery::on(function (array $query) {
                    return ($query['msisdn'] ?? null) === '+255793045401'
                        && ($query['iccid'] ?? null) === '8925500000000000101';
                }))
                ->andReturn(Http::response(['status' => 'SUCCESS'], 200));
        });

        $result = app(VodacomActivationService::class)->activateIfNeeded($esim);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['skipped']);
        $this->assertNotNull($esim->fresh()->vodacom_activated_at);
    }

    public function test_activate_if_needed_skips_when_already_activated(): void
    {
        $esim = Esim::query()->create([
            'msisdn' => '255793045402',
            'sim_type' => Esim::SIM_TYPE_ESIM,
            'status' => 'MANAGED',
            'provider_status' => Esim::PROVIDER_STATUS_ACTIVE,
            'network_id' => 1,
            'vodacom_activated_at' => now(),
        ]);

        $this->mock(VodacomSimManagerService::class, function ($mock) {
            $mock->shouldReceive('post')->never();
        });

        $result = app(VodacomActivationService::class)->activateIfNeeded($esim);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['skipped']);
    }
}
