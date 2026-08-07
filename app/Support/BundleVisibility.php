<?php

namespace App\Support;

use App\Models\Bundle;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class BundleVisibility
{
    /**
     * Admin-only test SKU: free Starter (e.g. 25 MB @ USD 0).
     */
    public static function isAdminOnlyBundle(Bundle $bundle): bool
    {
        if (data_get($bundle->metadata, 'admin_only') === true) {
            return true;
        }

        if ((float) $bundle->price_usd > 0) {
            return false;
        }

        $alias = strtolower(trim((string) ($bundle->alias ?? '')));
        if ($alias === 'starter') {
            return true;
        }

        $name = strtolower((string) ($bundle->name ?? ''));
        $dataMb = self::resolveDataMb($bundle);

        if (str_contains($name, '25mb') && (str_contains($name, '30day') || str_contains($name, '30days'))) {
            return true;
        }

        if ($dataMb === 25 && (float) $bundle->price_usd <= 0) {
            return true;
        }

        return false;
    }

    public static function visibleTo(?User $user, Bundle $bundle): bool
    {
        if (! self::isAdminOnlyBundle($bundle)) {
            return true;
        }

        return $user?->isAdmin() ?? false;
    }

    /**
     * @param  iterable<int, Bundle>  $bundles
     * @return list<Bundle>
     */
    public static function filterBundles(iterable $bundles, ?User $user): array
    {
        $visible = [];

        foreach ($bundles as $bundle) {
            if (self::visibleTo($user, $bundle)) {
                $visible[] = $bundle;
            }
        }

        return $visible;
    }

    public static function resolveOptionalUser(Request $request): ?User
    {
        $user = $request->user();
        if ($user instanceof User) {
            return $user;
        }

        $token = $request->bearerToken();
        if (! $token) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (! $accessToken) {
            return null;
        }

        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            return null;
        }

        $tokenable = $accessToken->tokenable;

        return $tokenable instanceof User ? $tokenable : null;
    }

    private static function resolveDataMb(Bundle $bundle): int
    {
        if ($bundle->data_mb !== null && (int) $bundle->data_mb > 0) {
            return (int) $bundle->data_mb;
        }

        if ($bundle->bundle_size_in_mb !== null && (int) $bundle->bundle_size_in_mb > 0) {
            return (int) $bundle->bundle_size_in_mb;
        }

        if ($bundle->bundle_size !== null && strtoupper((string) ($bundle->unit ?? '')) === 'MB') {
            return (int) $bundle->bundle_size;
        }

        if ($bundle->bundle_size !== null) {
            $size = (float) $bundle->bundle_size;
            if ($size > 0 && $size <= 512) {
                return (int) round($size);
            }
        }

        return 0;
    }
}
