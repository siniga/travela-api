<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bundles', 'price') && ! Schema::hasColumn('bundles', 'price_usd')) {
            DB::statement('ALTER TABLE bundles CHANGE price price_usd DECIMAL(12,2) NOT NULL');
        }

        if (! Schema::hasColumn('bundles', 'price_tzs')) {
            Schema::table('bundles', function (Blueprint $table) {
                $table->unsignedInteger('price_tzs')->nullable()->after('price_usd');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bundles', 'price_tzs')) {
            Schema::table('bundles', function (Blueprint $table) {
                $table->dropColumn('price_tzs');
            });
        }

        if (Schema::hasColumn('bundles', 'price_usd') && ! Schema::hasColumn('bundles', 'price')) {
            DB::statement('ALTER TABLE bundles CHANGE price_usd price DECIMAL(12,2) NOT NULL');
        }
    }
};
