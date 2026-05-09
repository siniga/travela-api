<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vodacom / SIM catalog: `bundles.bundle_size` (GB) → `sim_bundle_id`.
     *
     * Verified tiers:
     * - 3232 → 10 GB
     * - 3233 → 15 GB
     * - 3234 → 30 GB
     * - 28   → 50 GB
     *
     * Others (1 / 3 / 5 GB) are provisional until you lock IDs with the SIM product API.
     */
    private const SIM_BUNDLE_IDS_BY_GB = [
        1 => 25,
        3 => 3231,
        5 => 3230,
        10 => 3232,
        15 => 3233,
        30 => 3234,
        50 => 28,
    ];

    public function up(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            if (! Schema::hasColumn('bundles', 'sim_bundle_id')) {
                $table->unsignedBigInteger('sim_bundle_id')->nullable()->after('external_id');
                $table->index('sim_bundle_id', 'bundles_sim_bundle_id_idx');
            }
        });

        foreach (self::SIM_BUNDLE_IDS_BY_GB as $gb => $simId) {
            DB::table('bundles')
                ->where('bundle_size', $gb)
                ->update(['sim_bundle_id' => $simId]);
        }
    }

    public function down(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            if (Schema::hasColumn('bundles', 'sim_bundle_id')) {
                $table->dropIndex('bundles_sim_bundle_id_idx');
                $table->dropColumn('sim_bundle_id');
            }
        });
    }
};
