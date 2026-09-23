<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->string('kind')->default('workspace');
            $table->foreignUlid('simulation_scenario_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('context')->nullable();
            $table->jsonb('output_data')->nullable();
            $table->index(['simulation_scenario_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropIndex(['simulation_scenario_id', 'kind', 'created_at']);
            $table->dropConstrainedForeignId('simulation_scenario_id');
            $table->dropColumn(['kind', 'context', 'output_data']);
        });
    }
};
