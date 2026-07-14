<?php

namespace Tests\Unit;

use App\Services\ResendMailConfigurator;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ResendMailConfiguratorTest extends TestCase
{
    public function test_keeps_configured_address_when_domain_is_verified(): void
    {
        config([
            'mail.from.address' => 'noreply@thetravela.com',
            'services.resend.key' => 're_test',
            'services.resend.domain' => 'thetravela.com',
        ]);

        Cache::shouldReceive('remember')
            ->once()
            ->andReturn(['thetravela.com']);

        $configurator = new ResendMailConfigurator();

        $this->assertSame('noreply@thetravela.com', $configurator->resolvedFromAddress());
    }

    public function test_falls_back_to_verified_domain_when_configured_subdomain_is_not_verified(): void
    {
        config([
            'mail.from.address' => 'noreply@mail.thetravela.com',
            'services.resend.key' => 're_test',
            'services.resend.domain' => 'thetravela.com',
        ]);

        Cache::shouldReceive('remember')
            ->once()
            ->andReturn(['thetravela.com']);

        $configurator = new ResendMailConfigurator();

        $this->assertSame('noreply@thetravela.com', $configurator->resolvedFromAddress());
    }
}
