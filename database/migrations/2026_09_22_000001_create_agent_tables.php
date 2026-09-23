<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
        });

        Schema::create('agent_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_key');
            $table->string('status')->default('queued');
            $table->string('driver');
            $table->string('provider');
            $table->string('model');
            $table->string('prompt_version');
            $table->text('input');
            $table->text('output')->nullable();
            $table->string('error')->nullable();
            $table->jsonb('limits');
            $table->jsonb('usage')->nullable();
            $table->unsignedInteger('tool_calls')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'request_key']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('run_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('agent_run_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->jsonb('data');
            $table->timestampsTz();
        });

        Schema::create('tool_approvals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agent_run_id')->constrained()->cascadeOnDelete();
            $table->string('tool');
            $table->string('status')->default('pending');
            $table->jsonb('arguments');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('tool_approval_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('title', 160);
            $table->text('body');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
        Schema::dropIfExists('tool_approvals');
        Schema::dropIfExists('run_events');
        Schema::dropIfExists('agent_runs');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_admin'));
    }
};
