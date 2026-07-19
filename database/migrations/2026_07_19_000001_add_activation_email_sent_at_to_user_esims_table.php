<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_esims', function (Blueprint $table) {
            if (! Schema::hasColumn('user_esims', 'activation_email_sent_at')) {
                $table->timestamp('activation_email_sent_at')->nullable()->after('device_activated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_esims', function (Blueprint $table) {
            if (Schema::hasColumn('user_esims', 'activation_email_sent_at')) {
                $table->dropColumn('activation_email_sent_at');
            }
        });
    }
};
