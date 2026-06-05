<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_esims', function (Blueprint $table) {
            if (! Schema::hasColumn('user_esims', 'physical_issued_at')) {
                $table->timestamp('physical_issued_at')->nullable()->after('last_recharged_at');
            }
            if (! Schema::hasColumn('user_esims', 'physical_issued_by')) {
                $table->foreignId('physical_issued_by')->nullable()->after('physical_issued_at')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('user_esims', 'physical_issued_location')) {
                $table->string('physical_issued_location', 255)->nullable()->after('physical_issued_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_esims', function (Blueprint $table) {
            if (Schema::hasColumn('user_esims', 'physical_issued_location')) {
                $table->dropColumn('physical_issued_location');
            }
            if (Schema::hasColumn('user_esims', 'physical_issued_by')) {
                $table->dropConstrainedForeignId('physical_issued_by');
            }
            if (Schema::hasColumn('user_esims', 'physical_issued_at')) {
                $table->dropColumn('physical_issued_at');
            }
        });
    }
};
