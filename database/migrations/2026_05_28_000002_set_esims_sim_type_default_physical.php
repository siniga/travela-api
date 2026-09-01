<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('esims')->update(['sim_type' => 'physical']);

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                "ALTER TABLE `esims` MODIFY `sim_type` ENUM('esim', 'physical') NOT NULL DEFAULT 'physical'"
            );
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                "ALTER TABLE `esims` MODIFY `sim_type` ENUM('esim', 'physical') NOT NULL DEFAULT 'esim'"
            );
        }
    }
};
