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

    public function test_extracts_wrapped_and_lowercase_balance_records(): void
    {
        $service = app(VodacomBalanceService::class);
        $method = new \ReflectionMethod(VodacomBalanceService::class, 'extractBalanceRecords');
        $method->setAccessible(true);

        $wrapped = $method->invoke($service, [
            'data' => [
                'msisdn' => '255793045340',
                'balances' => ['data' => 1024, 'airtime' => 0],
            ],
        ]);

        $this->assertCount(1, $wrapped);
        $this->assertSame('255793045340', $wrapped[0]['msisdn']);
        $this->assertSame(['data' => 1024, 'airtime' => 0], $wrapped[0]['balances']);
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
