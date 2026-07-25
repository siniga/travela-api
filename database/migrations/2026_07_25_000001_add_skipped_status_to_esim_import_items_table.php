<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE esim_import_items MODIFY status ENUM('pending', 'processing', 'completed', 'failed', 'skipped') NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE esim_import_items MODIFY status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending'"
        );
    }
};
