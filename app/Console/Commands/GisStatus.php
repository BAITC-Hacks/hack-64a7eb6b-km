<?php

namespace App\Console\Commands;

use App\Models\GisImport;
use App\Models\GisLayer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GisStatus extends Command
{
    protected $signature = 'gis:status {--json}';

    protected $description = 'Report GIS archive completeness and retained storage';

    public function handle(): int
    {
        $layers = GisLayer::query()->whereNull('user_id')->orderBy('title')->get()->map(function (GisLayer $layer): array {
            $version = $layer->versions()->latest('id')->first();
            $assets = $version ? DB::table('gis_version_assets as va')->join('gis_assets as a', 'a.hash', '=', 'va.asset_hash')->where('version_id', $version->id) : null;

            return ['id' => $layer->id, 'title' => $layer->title, 'kind' => $layer->kind, 'source_url' => $layer->source_url, 'status' => $version->status ?? $layer->status, 'version_id' => $version?->id, 'published_version_id' => $layer->active_version_id, 'observed_at' => $version?->observed_at, 'published_at' => $version?->published_at, 'complete' => $version?->status === 'published', 'features' => $version->feature_count ?? 0, 'expected' => $version?->expected_count, 'files' => $assets ? (clone $assets)->count() : 0, 'tiles' => $assets ? (clone $assets)->where('va.name', 'like', 'tiles/%')->count() : 0, 'bytes' => $assets ? (clone $assets)->sum('a.bytes') : 0, 'coverage' => $version?->coverage, 'cursor' => $version?->cursor, 'error' => $version ? $version->error : $layer->error];
        });
        if ($this->option('json')) {
            $this->line(json_encode(['generated_at' => now()->toISOString(), 'timezone' => 'Asia/Almaty', 'schedule' => '03:00 daily', 'history_before_first_import' => false, 'imports' => GisImport::query()->latest()->limit(10)->get(['id', 'status', 'manifest', 'errors', 'created_at', 'updated_at']), 'summary' => ['discovered' => $layers->count(), 'published' => $layers->where('complete', true)->count(), 'partial_seed' => $layers->where('status', 'seeded')->count(), 'unavailable' => $layers->where('status', 'unavailable')->count(), 'features' => $layers->sum('features'), 'expected_features' => $layers->sum('expected'), 'files' => $layers->sum('files')], 'layers' => $layers, 'unique_bytes' => DB::table('gis_assets')->sum('bytes'), 'unique_files' => DB::table('gis_assets')->count(), 'limitations' => ['Неполные слои включены в локальный seed: отсутствующие объекты не являются удалёнными; растры покрывают только сохранённые участки.', 'Некоторые исходные геометрии некорректны по OGC. Seed сохраняет координаты и исходные ответы без исправлений.', 'История внешних источников начинается с первого наблюдения.', 'Векторный генплан и растровый генплан 2026 года являются отдельными источниками; их соответствие не подтверждено.', 'Архив растров содержит доступное отображение через export, а не исходные проекты или исходные JPG.', 'Идентичные физические слои объединены; объекты разных физических источников могут пересекаться.', 'Оформление ArcGIS сохранено в метаданных; сложные выражения Arcade и специальные типы символов могут отображаться упрощённо.']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Layer', 'Type', 'State', 'Objects', 'Expected', 'Files', 'Error'], $layers->map(fn (array $layer): array => [$layer['title'], $layer['kind'], $layer['status'], $layer['features'], $layer['expected'], $layer['files'], $layer['error']])->all());
        }

        return self::SUCCESS;
    }
}
