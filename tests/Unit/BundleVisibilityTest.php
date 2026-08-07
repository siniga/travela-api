<?php

namespace Tests\Unit;

use App\Models\Bundle;
use App\Models\User;
use App\Support\BundleVisibility;
use PHPUnit\Framework\TestCase;

class BundleVisibilityTest extends TestCase
{
    public function test_admin_only_starter_25mb_free_bundle(): void
    {
        $bundle = new Bundle([
            'alias' => 'Starter',
            'name' => 'Internet - 30Days - 25MB',
            'data_mb' => 25,
            'validity_days' => 30,
            'price_usd' => 0,
        ]);

        $this->assertTrue(BundleVisibility::isAdminOnlyBundle($bundle));
        $this->assertFalse(BundleVisibility::visibleTo(null, $bundle));
        $this->assertFalse(BundleVisibility::visibleTo(new User(['role' => 'user']), $bundle));

        $admin = new User(['role' => 'admin']);
        $this->assertTrue(BundleVisibility::visibleTo($admin, $bundle));
    }

    public function test_public_paid_bundle_stays_visible(): void
    {
        $bundle = new Bundle([
            'alias' => 'Starter',
            'name' => 'Starter 1GB',
            'data_mb' => 1024,
            'price_usd' => 20,
        ]);

        $this->assertFalse(BundleVisibility::isAdminOnlyBundle($bundle));
        $this->assertTrue(BundleVisibility::visibleTo(null, $bundle));
    }
}
