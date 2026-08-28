<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 64)->unique();
            $table->string('transaction_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->default('evpay');
            $table->string('payment_method')->default('mobile_money');
            $table->string('operator');
            $table->string('phone_number', 20);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('TZS');
            $table->string('product')->nullable();
            $table->string('status')->default('PENDING');
            $table->text('provider_message')->nullable();
            $table->json('provider_response')->nullable();
            $table->json('request_payload')->nullable();
            $table->text('request_body')->nullable();
            $table->string('fsp_reference')->nullable();
            $table->string('cbs_reference')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
