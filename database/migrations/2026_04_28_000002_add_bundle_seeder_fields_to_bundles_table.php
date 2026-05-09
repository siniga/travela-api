<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            if (!Schema::hasColumn('bundles', 'network_id')) {
                $table->unsignedBigInteger('network_id')->nullable()->after('country_provider_id');
                $table->index('network_id', 'bundles_network_id_idx');
            }

            if (!Schema::hasColumn('bundles', 'bundle_size')) {
                // Supports fractional sizes like 7.5GB.
                $table->decimal('bundle_size', 10, 2)->nullable()->after('price');
            }

            if (!Schema::hasColumn('bundles', 'bundle_size_in_mb')) {
                $table->unsignedInteger('bundle_size_in_mb')->nullable()->after('bundle_size');
            }

            if (!Schema::hasColumn('bundles', 'unit')) {
                $table->string('unit', 10)->nullable()->after('bundle_size_in_mb');
            }

            if (!Schema::hasColumn('bundles', 'product_code')) {
                $table->string('product_code', 50)->nullable()->after('unit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            if (Schema::hasColumn('bundles', 'product_code')) {
                $table->dropColumn('product_code');
            }
            if (Schema::hasColumn('bundles', 'unit')) {
                $table->dropColumn('unit');
            }
            if (Schema::hasColumn('bundles', 'bundle_size_in_mb')) {
                $table->dropColumn('bundle_size_in_mb');
            }
            if (Schema::hasColumn('bundles', 'bundle_size')) {
                $table->dropColumn('bundle_size');
            }
            if (Schema::hasColumn('bundles', 'network_id')) {
                $table->dropIndex('bundles_network_id_idx');
                $table->dropColumn('network_id');
            }
        });
    }
};

