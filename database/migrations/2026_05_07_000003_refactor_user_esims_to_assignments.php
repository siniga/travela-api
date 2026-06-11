<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Backfill inventory from any existing user_esims rows (legacy)
        if (Schema::hasTable('user_esims')) {
            $legacy = DB::table('user_esims')->get();

            foreach ($legacy as $row) {
                if (! $row->msisdn) {
                    continue;
                }

                DB::table('esims')->updateOrInsert(
                    ['msisdn' => $row->msisdn],
                    [
                        'sim_id' => $row->sim_id ?? null,
                        'network_id' => $row->network_id ?? 1,
                        'iccid' => $row->iccid ?? null,
                        'imsi' => $row->imsi ?? null,
                        'description' => $row->description ?? null,
                        'status' => 'MANAGED', // legacy rows were already "taken"
                        'created_at' => $row->created_at ?? now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        // 2) Add esim_id to assignments table
        Schema::table('user_esims', function (Blueprint $table) {
            if (! Schema::hasColumn('user_esims', 'esim_id')) {
                $table->foreignId('esim_id')->nullable()->after('user_id')->index();
            }
        });

        // 3) Set esim_id for legacy rows (match by msisdn)
        $legacyRows = DB::table('user_esims')->select('id', 'msisdn')->get();
        foreach ($legacyRows as $row) {
            $esimId = DB::table('esims')->where('msisdn', $row->msisdn)->value('id');
            if ($esimId) {
                DB::table('user_esims')->where('id', $row->id)->update(['esim_id' => $esimId]);
            }
        }

        // 4) Rebuild constraints for assignment model:
        // - user_id NOT NULL
        // - esim_id NOT NULL + unique (cannot be re-assigned)
        // - keep old rows as-is; new assignments must comply
        $this->dropLegacyUserEsimConstraints();

        // Remove any legacy rows that cannot be represented as assignments.
        // (Earlier seeds may have inserted inventory-like rows with user_id NULL.)
        DB::table('user_esims')->whereNull('user_id')->delete();
        DB::table('user_esims')->whereNull('esim_id')->delete();

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('user_esims', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable(false)->change();
                $table->unsignedBigInteger('esim_id')->nullable(false)->change();
            });
        }

        Schema::table('user_esims', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('esim_id')->references('id')->on('esims')->cascadeOnDelete();
            $table->unique('esim_id', 'user_esims_esim_id_unique');
        });

        // 5) Drop legacy SIM detail columns from user_esims (inventory now lives in esims)
        Schema::table('user_esims', function (Blueprint $table) {
            foreach (['sim_id', 'msisdn', 'network_id', 'iccid', 'imsi', 'description', 'status'] as $col) {
                if (Schema::hasColumn('user_esims', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void
    {
        // Not supported (destructive refactor).
    }

    private function dropLegacyUserEsimConstraints(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $indexes = collect(DB::select('SHOW INDEX FROM `user_esims`'))
                ->pluck('Key_name')
                ->unique()
                ->values()
                ->all();

            foreach (['user_esims_user_id_foreign', 'user_esims_msisdn_unique', 'user_esims_user_id_msisdn_unique', 'user_esims_msisdn_index'] as $key) {
                if (in_array($key, $indexes, true) && ! str_ends_with($key, '_foreign')) {
                    DB::statement("ALTER TABLE `user_esims` DROP INDEX `$key`");
                }
            }

            $fkNames = collect(DB::select("
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'user_esims'
                  AND REFERENCED_TABLE_NAME IS NOT NULL
            "))->pluck('CONSTRAINT_NAME')->all();

            foreach (['user_esims_user_id_foreign', 'user_esims_esim_id_foreign'] as $fk) {
                if (in_array($fk, $fkNames, true)) {
                    DB::statement("ALTER TABLE `user_esims` DROP FOREIGN KEY `$fk`");
                }
            }

            return;
        }

        foreach ([
            fn () => Schema::table('user_esims', fn (Blueprint $table) => $table->dropForeign(['user_id'])),
            fn () => Schema::table('user_esims', fn (Blueprint $table) => $table->dropUnique(['msisdn'])),
            fn () => Schema::table('user_esims', fn (Blueprint $table) => $table->dropUnique(['user_id', 'msisdn'])),
            fn () => Schema::table('user_esims', fn (Blueprint $table) => $table->dropIndex(['msisdn'])),
        ] as $drop) {
            $this->trySchemaChange($drop);
        }
    }

    private function trySchemaChange(callable $change): void
    {
        try {
            $change();
        } catch (\Throwable) {
            // Constraint/index may already be absent depending on migration order.
        }
    }
};

