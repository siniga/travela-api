<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_esims', function (Blueprint $table) {
            // Drop old FK + unique(user_id, msisdn)
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id', 'msisdn']);
        });

        Schema::table('user_esims', function (Blueprint $table) {
            // Allow assigning user later
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::table('user_esims', function (Blueprint $table) {
            // User can be deleted without deleting SIM record
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            // Use msisdn uniqueness (as requested)
            $table->unique('msisdn');
        });
    }

    public function down(): void
    {
        Schema::table('user_esims', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['msisdn']);
        });

        Schema::table('user_esims', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::table('user_esims', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'msisdn']);
        });
    }
};

