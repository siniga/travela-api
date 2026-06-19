<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('esims', function (Blueprint $table) {
            if (! Schema::hasColumn('esims', 'qr_code_path')) {
                $table->string('qr_code_path')->nullable()->after('iccid');
            }
            if (! Schema::hasColumn('esims', 'qr_code_data')) {
                $table->text('qr_code_data')->nullable()->after('qr_code_path');
            }
            if (! Schema::hasColumn('esims', 'sale_status')) {
                $table->enum('sale_status', ['available', 'sold', 'used'])
                    ->default('available')
                    ->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('esims', function (Blueprint $table) {
            if (Schema::hasColumn('esims', 'sale_status')) {
                $table->dropColumn('sale_status');
            }
            if (Schema::hasColumn('esims', 'qr_code_data')) {
                $table->dropColumn('qr_code_data');
            }
            if (Schema::hasColumn('esims', 'qr_code_path')) {
                $table->dropColumn('qr_code_path');
            }
        });
    }
};
