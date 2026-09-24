<?php

namespace Tests\Unit;

use App\Models\Bundle;
use App\Models\User;
use App\Support\BundleVisibility;
use Tests\TestCase;

class BundleVisibilityTest extends TestCase
{
    public function test_free_starter_alias_is_admin_only(): void
    {
        $bundle = new Bundle([
            'alias' => 'Starter',
            'name' => 'Internet - 30Days - 25MB',
            'data_mb' => 25,
            'validity_days' => 30,
            'price_usd' => 0,
        ]);

        $this->assertTrue(BundleVisibility::isAdminOnlyBundle($bundle));

        app()->detectEnvironment(fn () => 'local');
        $this->assertTrue(BundleVisibility::visibleTo(null, $bundle));
        $this->assertTrue(BundleVisibility::visibleTo(new User(['role' => 'user']), $bundle));

        app()->detectEnvironment(fn () => 'production');
        $this->assertFalse(BundleVisibility::visibleTo(null, $bundle));
        $this->assertFalse(BundleVisibility::visibleTo(new User(['role' => 'admin']), $bundle));
    }

    public function test_paid_starter_is_public(): void
    {
        $bundle = new Bundle([
            'alias' => 'Starter',
            'name' => 'Starter 1GB',
            'data_mb' => 1024,
            'price_usd' => 20,
        ]);

        app()->detectEnvironment(fn () => 'production');
        $this->assertFalse(BundleVisibility::isAdminOnlyBundle($bundle));
        $this->assertTrue(BundleVisibility::visibleTo(null, $bundle));
    }
}
