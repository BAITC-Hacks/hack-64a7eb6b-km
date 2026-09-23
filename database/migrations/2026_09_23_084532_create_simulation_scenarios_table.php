<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulation_scenarios', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('simulation_dataset_id')->constrained()->restrictOnDelete();
            $table->ulid('source_scenario_id')->nullable();
            $table->foreignUlid('tool_approval_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->uuid('request_key')->nullable();
            $table->string('title', 160);
            $table->string('calculator_version');
            $table->jsonb('selections');
            $table->jsonb('result');
            $table->jsonb('alternatives');
            $table->timestampsTz();
            $table->unique(['user_id', 'request_key']);
            $table->index(['user_id', 'created_at']);
        });
        Schema::table('simulation_scenarios', function (Blueprint $table) {
            $table->foreign('source_scenario_id')->references('id')->on('simulation_scenarios')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulation_scenarios');
    }
};
