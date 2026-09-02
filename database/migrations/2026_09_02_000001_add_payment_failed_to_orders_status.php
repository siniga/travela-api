<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `orders` MODIFY COLUMN `status` ENUM('draft', 'pending_payment', 'paid', 'processing', 'completed', 'cancelled', 'payment_failed') NOT NULL DEFAULT 'pending_payment'");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::table('orders')->where('status', 'payment_failed')->update(['status' => 'pending_payment']);

        DB::statement("ALTER TABLE `orders` MODIFY COLUMN `status` ENUM('draft', 'pending_payment', 'paid', 'processing', 'completed', 'cancelled') NOT NULL DEFAULT 'pending_payment'");
    }
};
