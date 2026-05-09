<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esims', function (Blueprint $table) {
            $table->id();
            $table->integer('sim_id')->nullable();
            $table->string('msisdn')->unique();
            $table->integer('network_id')->default(1);
            $table->string('iccid')->nullable()->unique();
            $table->string('imsi')->nullable()->unique();
            $table->string('description')->nullable();
            $table->enum('status', ['AVAILABLE', 'MANAGED'])->default('AVAILABLE');
            $table->timestamps();

            $table->index('msisdn');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esims');
    }
};

