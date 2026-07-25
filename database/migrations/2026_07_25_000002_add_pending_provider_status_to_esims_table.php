<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE esims MODIFY provider_status ENUM('pending', 'active', 'suspended') NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE esims MODIFY provider_status ENUM('active', 'suspended') NOT NULL DEFAULT 'active'"
        );
    }
};
