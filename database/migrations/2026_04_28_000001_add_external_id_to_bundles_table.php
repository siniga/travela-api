<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            if (!Schema::hasColumn('bundles', 'external_id')) {
                $table->unsignedBigInteger('external_id')->nullable()->after('country_provider_id');
                $table->index('external_id', 'bundles_external_id_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            if (Schema::hasColumn('bundles', 'external_id')) {
                $table->dropIndex('bundles_external_id_idx');
                $table->dropColumn('external_id');
            }
        });
    }
};

