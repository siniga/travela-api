<?php

namespace Tests\Unit;

use App\Services\Esim\VodacomBalanceService;
use App\Services\Esim\VodacomSimManagerService;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

class VodacomBalanceServiceTest extends TestCase
{
    public function test_request_balances_sends_e164_msisdn(): void
    {
        $vodacom = \Mockery::mock(VodacomSimManagerService::class);
        $vodacom->shouldReceive('get')
            ->once()
            ->with('/api/sims-balances', ['msisdn' => '+25583479408'])
            ->andReturn($this->queuedResponse());

        $this->app->instance(VodacomSimManagerService::class, $vodacom);

        $result = app(VodacomBalanceService::class)->requestBalancesForMsisdn('25583479408');

        $this->assertSame('queued', $result['status']);
        $this->assertSame(202, $result['http_status']);
    }

    public function test_request_balances_keeps_existing_plus_prefix(): void
    {
        $vodacom = \Mockery::mock(VodacomSimManagerService::class);
        $vodacom->shouldReceive('get')
            ->once()
            ->with('/api/sims-balances', ['msisdn' => '+255797053059'])
            ->andReturn($this->queuedResponse());

        $this->app->instance(VodacomSimManagerService::class, $vodacom);

        $result = app(VodacomBalanceService::class)->requestBalancesForMsisdn('+255797053059');

        $this->assertSame('queued', $result['status']);
    }

    private function queuedResponse(): Response
    {
        return new Response(new Psr7Response(
            202,
            ['Content-Type' => 'application/json'],
            json_encode(['message' => 'queued for callback']),
        ));
    }
}
