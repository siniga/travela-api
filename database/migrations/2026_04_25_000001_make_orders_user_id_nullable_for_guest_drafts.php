<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guest preorder drafts omit user_id until finalize.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `orders` MODIFY COLUMN `status` ENUM('draft', 'pending_payment', 'paid', 'processing', 'completed', 'cancelled') NOT NULL DEFAULT 'pending_payment'");
        }
    }

    public function down(): void
    {
        // Reverting to NOT NULL is invalid while guest drafts (`user_id` NULL) still exist.
        // Remove those orders (trip + order_items cascade) so the column change can succeed.
        DB::table('orders')->whereNull('user_id')->delete();

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `orders` MODIFY COLUMN `status` ENUM('pending_payment', 'paid', 'processing', 'completed', 'cancelled') NOT NULL DEFAULT 'pending_payment'");
        }
    }
};
