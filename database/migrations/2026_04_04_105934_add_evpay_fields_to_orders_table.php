<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_reference')->nullable()->unique()->after('draft_id');
            $table->string('payment_gateway')->nullable()->after('currency');
            $table->string('gateway_payment_id')->nullable()->after('payment_gateway');
            $table->string('payment_status')->nullable()->after('gateway_payment_id');
            $table->json('payment_payload')->nullable()->after('payment_status');
            $table->json('payment_callback')->nullable()->after('payment_payload');
            $table->timestamp('paid_at')->nullable()->after('payment_callback');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_reference',
                'payment_gateway',
                'gateway_payment_id',
                'payment_status',
                'payment_payload',
                'payment_callback',
                'paid_at',
            ]);
        });
    }
};