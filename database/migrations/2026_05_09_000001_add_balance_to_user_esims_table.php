<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_esims', function (Blueprint $table) {
            $table->decimal('balance', 15, 2)->nullable()->after('esim_id');
            $table->string('balance_currency', 10)->nullable()->after('balance');
            $table->timestamp('balance_fetched_at')->nullable()->after('balance_currency');
        });
    }

    public function down(): void
    {
        Schema::table('user_esims', function (Blueprint $table) {
            $table->dropColumn(['balance', 'balance_currency', 'balance_fetched_at']);
        });
    }
};
