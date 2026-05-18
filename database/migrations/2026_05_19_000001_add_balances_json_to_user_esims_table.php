<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_esims', function (Blueprint $table) {
            $table->json('balances')->nullable()->after('balance_fetched_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_esims', function (Blueprint $table) {
            $table->dropColumn('balances');
        });
    }
};
