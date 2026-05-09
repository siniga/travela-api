<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_esims', function (Blueprint $table) {
            $table->decimal('last_recharge_amount', 15, 2)->nullable()->after('balance_fetched_at');
            $table->string('last_recharge_reference', 100)->nullable()->after('last_recharge_amount');
            $table->string('last_recharge_status', 30)->nullable()->after('last_recharge_reference');
            $table->timestamp('last_recharged_at')->nullable()->after('last_recharge_status');
        });
    }

    public function down(): void
    {
        Schema::table('user_esims', function (Blueprint $table) {
            $table->dropColumn([
                'last_recharge_amount',
                'last_recharge_reference',
                'last_recharge_status',
                'last_recharged_at',
            ]);
        });
    }
};
