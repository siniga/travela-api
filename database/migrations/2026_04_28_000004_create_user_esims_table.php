<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_esims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->integer('sim_id')->nullable();
            $table->string('msisdn');
            $table->integer('network_id')->nullable();
            $table->string('iccid')->nullable();
            $table->string('imsi')->nullable();
            $table->string('description')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();

            $table->index('msisdn');
            $table->unique(['user_id', 'msisdn']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_esims');
    }
};

