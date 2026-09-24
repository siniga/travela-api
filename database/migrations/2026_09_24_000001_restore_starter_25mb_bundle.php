<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Restore the local-only Starter 25 MB test SKU (Vodacom product_id 66).
     */
    public function up(): void
    {
        if (! Schema::hasTable('bundles')) {
            return;
        }

        $dataTypeId = DB::table('bundle_types')->where('code', 'DATA')->value('id');
        if (! $dataTypeId) {
            $now = now();
            $dataTypeId = DB::table('bundle_types')->insertGetId([
                'code' => 'DATA',
                'name' => 'Data only',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $countryProviderId = DB::table('country_provider')->where('is_default', true)->value('id')
            ?? DB::table('country_provider')->value('id');

        if (! $countryProviderId) {
            return;
        }

        $now = now();
        $payload = [
            'bundle_type_id' => $dataTypeId,
            'country_provider_id' => $countryProviderId,
            'network_id' => null,
            'external_id' => null,
            'sim_bundle_id' => 66,
            'name' => 'Internet - 30Days - 25MB',
            'alias' => 'Starter',
            'validity_days' => 30,
            'data_mb' => 25,
            'voice_minutes' => null,
            'sms' => null,
            'price_usd' => 0,
            'price_tzs' => null,
            'bundle_size' => 25,
            'bundle_size_in_mb' => 25,
            'unit' => 'MB',
            'product_code' => null,
            'currency' => 'USD',
            'active' => true,
            'metadata' => json_encode([
                'source' => 'migration',
                'admin_only' => true,
                'local_only' => true,
            ]),
            'updated_at' => $now,
        ];

        $existingId = DB::table('bundles')
            ->where(function ($q) {
                $q->where('sim_bundle_id', 66)
                    ->orWhere(function ($inner) {
                        $inner->where('alias', 'Starter')
                            ->where('data_mb', 25)
                            ->where('price_usd', 0);
                    });
            })
            ->value('id');

        if ($existingId) {
            DB::table('bundles')->where('id', $existingId)->update($payload);

            return;
        }

        $payload['created_at'] = $now;
        DB::table('bundles')->insert($payload);
    }

    public function down(): void
    {
        if (! Schema::hasTable('bundles')) {
            return;
        }

        DB::table('bundles')
            ->where(function ($q) {
                $q->where('sim_bundle_id', 66)
                    ->orWhere(function ($inner) {
                        $inner->where('alias', 'Starter')
                            ->where('data_mb', 25)
                            ->where('price_usd', 0);
                    });
            })
            ->delete();
    }
};
