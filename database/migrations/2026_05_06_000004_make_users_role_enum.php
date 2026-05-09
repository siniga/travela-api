<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->enum('role', ['admin', 'agent', 'user'])->default('user')->after('email');
                $table->index('role');
            });

            return;
        }

        // Normalize any unexpected roles to 'user' before enforcing ENUM.
        DB::table('users')
            ->whereNotIn('role', ['admin', 'agent', 'user'])
            ->orWhereNull('role')
            ->update(['role' => 'user']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `users` MODIFY COLUMN `role` ENUM('admin','agent','user') NOT NULL DEFAULT 'user'");
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            return;
        }

        // Convert back to string for portability.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `users` MODIFY COLUMN `role` VARCHAR(255) NOT NULL DEFAULT 'user'");
        }
    }
};

