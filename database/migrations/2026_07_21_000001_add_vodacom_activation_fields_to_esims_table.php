<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('esims', function (Blueprint $table) {
            if (! Schema::hasColumn('esims', 'activation_status')) {
                $table->string('activation_status', 32)->nullable()->after('provider_status');
            }
            if (! Schema::hasColumn('esims', 'activation_error')) {
                $table->text('activation_error')->nullable()->after('activation_status');
            }
            if (! Schema::hasColumn('esims', 'vodacom_activation_response')) {
                $table->json('vodacom_activation_response')->nullable()->after('activation_error');
            }
            if (! Schema::hasColumn('esims', 'vodacom_activated_at')) {
                $table->timestamp('vodacom_activated_at')->nullable()->after('vodacom_activation_response');
            }
        });
    }

    public function down(): void
    {
        Schema::table('esims', function (Blueprint $table) {
            foreach (['vodacom_activated_at', 'vodacom_activation_response', 'activation_error', 'activation_status'] as $column) {
                if (Schema::hasColumn('esims', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
