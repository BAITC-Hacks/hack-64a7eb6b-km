<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulation_datasets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('version')->unique();
            $table->string('checksum', 64);
            $table->jsonb('data');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulation_datasets');
    }
};
