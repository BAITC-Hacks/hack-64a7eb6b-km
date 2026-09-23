<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        Schema::create('gis_layers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('source_key', 64)->nullable()->unique();
            $table->text('source_url')->nullable();
            $table->string('title');
            $table->string('kind', 30);
            $table->string('status', 30)->default('discovered');
            $table->jsonb('metadata')->default('{}');
            $table->jsonb('occurrences')->default('[]');
            $table->unsignedBigInteger('active_version_id')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('checked_at')->nullable();
            $table->timestampsTz();
            $table->index('user_id');
        });
        Schema::create('gis_imports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('status', 30)->default('discovering');
            $table->jsonb('errors')->default('[]');
            $table->timestampsTz();
        });
        Schema::create('gis_layer_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('gis_layer_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('gis_import_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('building');
            $table->jsonb('metadata')->default('{}');
            $table->jsonb('cursor')->default('{}');
            $table->jsonb('coverage')->nullable();
            $table->unsignedBigInteger('expected_count')->nullable();
            $table->unsignedBigInteger('feature_count')->default(0);
            $table->text('error')->nullable();
            $table->timestampTz('observed_at');
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->index(['gis_layer_id', 'published_at']);
        });
        Schema::create('gis_feature_contents', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('gis_layer_id')->constrained()->cascadeOnDelete();
            $table->string('source_id');
            $table->string('hash', 64);
            $table->jsonb('properties');
            $table->jsonb('raw')->nullable();
            $table->geometry('geometry', srid: 4326)->nullable();
            $table->timestampTz('created_at');
            $table->unique(['gis_layer_id', 'source_id', 'hash'], 'gis_feature_content_unique');
            $table->spatialIndex('geometry');
        });
        Schema::create('gis_version_features', function (Blueprint $table): void {
            $table->foreignId('version_id')->constrained('gis_layer_versions')->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained('gis_feature_contents')->cascadeOnDelete();
            $table->string('source_id');
            $table->primary(['version_id', 'source_id']);
            $table->index(['version_id', 'feature_id']);
            $table->index('feature_id');
        });
        Schema::create('gis_assets', function (Blueprint $table): void {
            $table->string('hash', 64)->primary();
            $table->string('path');
            $table->string('mime');
            $table->unsignedBigInteger('bytes');
            $table->timestampTz('created_at');
        });
        Schema::create('gis_version_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('gis_layer_versions')->cascadeOnDelete();
            $table->string('asset_hash', 64);
            $table->foreign('asset_hash')->references('hash')->on('gis_assets');
            $table->text('name');
            $table->string('name_hash', 64);
            $table->string('feature_source_id')->nullable();
            $table->unique(['version_id', 'name_hash']);
            $table->index(['version_id', 'feature_source_id']);
        });
        Schema::create('gis_map_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->jsonb('state');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        foreach (['gis_map_states', 'gis_version_assets', 'gis_assets', 'gis_version_features', 'gis_feature_contents', 'gis_layer_versions', 'gis_imports', 'gis_layers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
