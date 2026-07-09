<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('esim_import_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('esim_import_batches', 'sim_type')) {
                $table->string('sim_type', 20)->default('esim')->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('esim_import_batches', function (Blueprint $table) {
            if (Schema::hasColumn('esim_import_batches', 'sim_type')) {
                $table->dropColumn('sim_type');
            }
        });
    }
};
