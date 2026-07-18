<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_esims', function (Blueprint $table) {
            if (! Schema::hasColumn('user_esims', 'device_activated_at')) {
                $table->timestamp('device_activated_at')->nullable()->after('physical_issued_location');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_esims', function (Blueprint $table) {
            if (Schema::hasColumn('user_esims', 'device_activated_at')) {
                $table->dropColumn('device_activated_at');
            }
        });
    }
};
