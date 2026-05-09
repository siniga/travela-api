<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Size-based Vodacom/external SIM product IDs.
     *
     * Verified tiers:
     * - 3232 → 10 GB, 3233 → 15 GB, 3234 → 30 GB, 28 → 50 GB
     *
     * Provisional / confirm via GET /api/esim/products:
     * - 1 GB → `25` (nearest 30d plan in BundleSeeder; replace if you have a real 1 GB SKU).
     * - 3 GB → `3231` (placeholder adjacent to prepaid DataPre numbering).
     * - 5 GB → `3230` (DataPrePer 5 GB / 30 d in BundleSeeder).
     */
    private const SIM_IDS_BY_SIZE_GB = [
        1 => 25,
        3 => 3231,
        5 => 3230,
        10 => 3232,
        15 => 3233,
        30 => 3234,
        50 => 28,
    ];

    /**
     * Marketing tiers take precedence where alias is set (decouples decimal bundle_size quirks).
     */
    private const SIM_IDS_BY_ALIAS = [
        'Starter' => 25,
        'Explorer' => 3231,
        'Traveller' => 3230,
        'Nomad' => 3232,
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('bundles', 'sim_bundle_id')) {
            return;
        }

        foreach (self::SIM_IDS_BY_ALIAS as $alias => $simId) {
            DB::table('bundles')
                ->where('alias', $alias)
                ->update(['sim_bundle_id' => $simId]);
        }

        foreach (self::SIM_IDS_BY_SIZE_GB as $gb => $simId) {
            DB::table('bundles')
                ->where('bundle_size', $gb)
                ->whereNull('alias')
                ->update(['sim_bundle_id' => $simId]);
        }
    }

    public function down(): void
    {
        // Non-destructive; no rollback of specific IDs.
    }
};
