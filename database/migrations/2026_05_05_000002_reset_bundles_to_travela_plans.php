<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Resolve required foreign keys (create BundleType DATA if missing)
        $dataTypeId = DB::table('bundle_types')->where('code', 'DATA')->value('id');
        if (!$dataTypeId) {
            $now = now();
            $dataTypeId = DB::table('bundle_types')->insertGetId([
                'code' => 'DATA',
                'name' => 'Data only',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Pick a default CountryProvider (required by bundles table)
        $countryProviderId = DB::table('country_provider')->where('is_default', true)->value('id')
            ?? DB::table('country_provider')->value('id');

        if (!$countryProviderId) {
            // If your DB has no catalog rows yet, we cannot create bundles without a CountryProvider.
            // In that case, do nothing rather than failing migrations for fresh installs.
            return;
        }

        // Delete all bundles (use delete not truncate, to respect FK constraints from order_items)
        DB::table('bundles')->delete();

        $now = now();

        $rows = [
            [
                'alias' => 'Nomad',
                'name' => 'Nomad 10GB',
                'bundle_size' => 10,
                'price' => 90.00,
            ],
            [
                'alias' => 'Explorer',
                'name' => 'Explorer 3GB',
                'bundle_size' => 3,
                'price' => 24.00,
            ],
            [
                'alias' => 'Traveller',
                'name' => 'Traveller 5GB',
                'bundle_size' => 5,
                'price' => 18.00,
            ],
            [
                'alias' => 'Starter',
                'name' => 'Starter 1GB',
                'bundle_size' => 1,
                'price' => 5.00,
            ],
        ];

        $insert = array_map(function (array $r) use ($dataTypeId, $countryProviderId, $now) {
            return array_merge([
                'external_id' => null,
                'bundle_type_id' => $dataTypeId,
                'country_provider_id' => $countryProviderId,
                'network_id' => null,
                'validity_days' => 30,
                'voice_minutes' => null,
                'sms' => null,
                'currency' => 'USD',
                'unit' => 'GB',
                'product_code' => null,
                'active' => true,
                'metadata' => json_encode([
                    'source' => 'migration',
                    'version' => '2026-05-05',
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ], $r);
        }, $rows);

        DB::table('bundles')->insert($insert);
    }

    public function down(): void
    {
        // Reverting a destructive "reset" migration is not safe to automate.
        // Leave empty intentionally.
    }
};

