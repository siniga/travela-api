<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            if (!Schema::hasColumn('bundles', 'alias')) {
                $table->enum('alias', ['Nomad', 'Explorer', 'Traveller', 'Starter'])
                    ->nullable()
                    ->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            if (Schema::hasColumn('bundles', 'alias')) {
                $table->dropColumn('alias');
            }
        });
    }
};

