<?php

namespace App\Actions\Gis;

use App\Models\GisImport;
use App\Models\GisLayer;
use App\Models\GisLayerVersion;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Pdo\Pgsql;
use RuntimeException;

class GisSeedPackage
{
    private const TABLES = ['gis_imports', 'gis_layers', 'gis_layer_versions', 'gis_feature_contents', 'gis_version_features', 'gis_assets', 'gis_version_assets'];

    public function __construct(private GisTiles $tiles, private GisSeedSelection $selection) {}

    public function freezeIncomplete(): void
    {
        $versions = GisLayerVersion::query()->whereHas('layer', fn ($query) => $query->whereNull('user_id'))->whereIn('status', ['building', 'failed'])->get();
        foreach ($versions as $version) {
            if ($version->layer->kind === 'raster') {
                $this->tiles->completeAvailableRaster($version);
            }
            DB::transaction(function () use ($version): void {
                $layer = $version->layer()->lockForUpdate()->firstOrFail();
                $version->refresh();
                $coverage = $version->coverage ?? [];
                if ($layer->kind === 'vector') {
                    $extent = DB::selectOne('SELECT ST_XMin(b) AS west, ST_YMin(b) AS south, ST_XMax(b) AS east, ST_YMax(b) AS north FROM (SELECT ST_Extent(f.geometry) AS b FROM gis_version_features vf JOIN gis_feature_contents f ON f.id=vf.feature_id WHERE vf.version_id=?) s', [$version->id]);
                    $coverage['bbox4326'] = $extent?->west !== null ? [(float) $extent->west, (float) $extent->south, (float) $extent->east, (float) $extent->north] : null;
                }
                $version->update(['status' => 'seeded', 'feature_count' => DB::table('gis_version_features')->where('version_id', $version->id)->count(), 'published_at' => now(), 'coverage' => $coverage + ['complete' => false], 'metadata' => $version->metadata + ['seed' => ['complete' => false, 'original_status' => $version->status, 'frozen_at' => now()->toISOString(), 'reason' => 'Импорт остановлен пользователем. Сохранены все полученные данные; отсутствующие объекты не считаются удалёнными.']]]);
                if (! $layer->active_version_id) {
                    $layer->update(['active_version_id' => $version->id, 'status' => 'partial']);
                }
            });
        }
        GisImport::query()->whereIn('status', ['discovering', 'running', 'partial'])->update(['status' => 'seeded']);
    }

    /** @return array<string, mixed> */
    public function export(string $path, bool $compact = false): array
    {
        if (file_exists($path)) {
            throw new RuntimeException('Каталог seed уже существует. Укажите новый --path.');
        }
        $directory = $path.'.building-'.Str::ulid();
        File::ensureDirectoryExists($directory.'/data');
        File::ensureDirectoryExists($directory.'/blobs');
        $manifest = ['format' => 1, 'profile' => $compact ? 'compact' : 'full', 'limit_bytes' => $compact ? config('gis.seed_max_bytes') : null, 'created_at' => now()->toISOString(), 'tables' => [], 'assets' => [], 'layers' => []];
        DB::transaction(function () use ($directory, $compact, &$manifest): void {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $publicIds = DB::table('gis_layers')->whereNull('user_id')->select('id');
            $versionIds = $compact ? $this->selection->versions() : DB::table('gis_layer_versions')->whereIn('gis_layer_id', $publicIds)->select('id');
            $selectedIds = array_values(array_map(intval(...), (clone $versionIds)->pluck('id')->all()));
            $versionAssets = $compact ? $this->selection->assets($versionIds) : DB::table('gis_version_assets')->whereIn('version_id', $versionIds);
            $portalAssets = [];
            foreach (GisImport::query()->get() as $import) {
                foreach ($import->manifest ?? [] as $map) {
                    foreach (['item_hash', 'data_hash'] as $key) {
                        if (isset($map[$key])) {
                            $portalAssets[] = $map[$key];
                        }
                    }
                }
            }
            foreach (self::TABLES as $table) {
                $query = DB::table($table);
                $key = 'id';
                match ($table) {
                    'gis_layers' => $query->whereNull('user_id'),
                    'gis_layer_versions' => $query->whereIn('id', $versionIds),
                    'gis_feature_contents' => $query->whereIn('id', DB::table('gis_version_features')->whereIn('version_id', $versionIds)->select('feature_id')),
                    'gis_version_assets' => $query->whereIn('id', (clone $versionAssets)->select('id')),
                    'gis_version_features' => $query->whereIn('version_id', $versionIds),
                    'gis_assets' => $query->where(fn ($assets) => $assets->whereIn('hash', (clone $versionAssets)->select('asset_hash'))->orWhereIn('hash', $portalAssets)),
                    default => null,
                };
                if ($table === 'gis_feature_contents') {
                    $query->selectRaw("id, gis_layer_id, source_id, hash, properties, raw, encode(ST_AsEWKB(geometry), 'hex') AS geometry, created_at");
                }
                if ($table === 'gis_assets') {
                    $key = 'hash';
                }
                $rows = $table === 'gis_version_features' ? $this->members($versionIds) : $query->lazyById(200, $key);
                if ($compact) {
                    $rows = $this->selection->rows($table, $rows, $selectedIds);
                }
                $manifest['tables'][$table] = $this->writeRows($directory, $table, $rows);
            }
            foreach ($this->readRows($directory, $manifest['tables']['gis_assets']) as $asset) {
                $hash = $asset['hash'];
                $input = Storage::disk(config('gis.disk'))->readStream($asset['path']);
                if ($input === null) {
                    throw new RuntimeException('Отсутствует файл архива: '.$hash);
                }
                $output = fopen($directory.'/blobs/'.$hash, 'wb');
                if ($output === false) {
                    throw new RuntimeException('Не удалось записать файл seed.');
                }
                stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
                if (hash_file('sha256', $directory.'/blobs/'.$hash) !== $hash) {
                    throw new RuntimeException('Повреждён файл архива: '.$hash);
                }
                $manifest['assets'][$hash] = (int) $asset['bytes'];
            }
            $versions = [];
            foreach ($this->readRows($directory, $manifest['tables']['gis_layer_versions']) as $version) {
                $versions[$version['gis_layer_id']] = array_diff_key($version, array_flip(['metadata', 'cursor']));
            }
            foreach ($this->readRows($directory, $manifest['tables']['gis_layers']) as $layer) {
                $version = $versions[$layer['id']] ?? null;
                $manifest['layers'][] = ['id' => $layer['id'], 'title' => $layer['title'], 'source_url' => $layer['source_url'], 'kind' => $layer['kind'], 'included' => $version !== null, 'status' => $version['status'] ?? $layer['status'], 'complete' => ($version['status'] ?? null) === 'published', 'features' => $version['feature_count'] ?? 0, 'expected' => $version['expected_count'] ?? null, 'observed_at' => $version['observed_at'] ?? null, 'coverage' => isset($version['coverage']) ? json_decode($version['coverage'], true) : null, 'error' => $version['error'] ?? $layer['error']];
            }
        });
        $payloadBytes = array_sum($manifest['assets']) + array_sum(array_map(fn (array $parts): int => array_sum(array_column($parts, 'bytes')), $manifest['tables']));
        $manifest['package_bytes'] = 0;
        do {
            $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $previous = $manifest['package_bytes'];
            $manifest['package_bytes'] = $payloadBytes + strlen($json);
        } while ($previous !== $manifest['package_bytes']);
        if ($compact && $manifest['package_bytes'] > config('gis.seed_max_bytes')) {
            File::deleteDirectory($directory);
            throw new RuntimeException('Компактный seed превышает лимит: '.$manifest['package_bytes'].' байт. Уменьшите список gis.seed_sources или gis.seed_tile_zoom.');
        }
        File::put($directory.'/manifest.json', $json);
        if (! rename($directory, $path)) {
            throw new RuntimeException('Не удалось завершить пакет seed.');
        }

        return $manifest;
    }

    /** @return \Generator<int, \stdClass> */
    private function members(Builder $versionIds): \Generator
    {
        foreach ($versionIds->orderBy('id')->pluck('id') as $versionId) {
            yield from DB::table('gis_version_features')->where('version_id', $versionId)->lazyById(1000, 'feature_id');
        }
    }

    /** @param iterable<object> $rows
     * @return list<array{file: string, sha256: string, rows: int, bytes: int}>
     */
    private function writeRows(string $directory, string $table, iterable $rows): array
    {
        $parts = [];
        $stream = null;
        $bytes = $count = 0;
        $name = '';
        foreach ($rows as $row) {
            if ($stream === null) {
                $name = 'data/'.$table.'-'.count($parts).'.ndjson.gz';
                $stream = gzopen($directory.'/'.$name, 'wb6');
                if ($stream === false) {
                    throw new RuntimeException('Не удалось создать seed.');
                }
            }
            $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
            if (gzwrite($stream, $line) !== strlen($line)) {
                throw new RuntimeException('Не удалось записать seed.');
            }
            $count++;
            $bytes += strlen($line);
            if ($bytes >= 32 * 1024 * 1024) {
                gzclose($stream);
                $parts[] = $this->part($directory, $name, $count);
                $stream = null;
                $bytes = $count = 0;
            }
        }
        if ($stream !== null) {
            gzclose($stream);
            $parts[] = $this->part($directory, $name, $count);
        }

        return $parts;
    }

    /** @return array{file: string, sha256: string, rows: int, bytes: int} */
    private function part(string $directory, string $name, int $count): array
    {
        $hash = hash_file('sha256', $directory.'/'.$name);
        $bytes = filesize($directory.'/'.$name);
        if ($hash === false || $bytes === false) {
            throw new RuntimeException('Не удалось проверить записанный seed.');
        }

        return ['file' => $name, 'sha256' => $hash, 'rows' => $count, 'bytes' => $bytes];
    }

    /** @param list<array{file: string, sha256: string, rows: int, bytes: int}> $parts
     * @return \Generator<int, array<string, mixed>>
     */
    private function readRows(string $directory, array $parts): \Generator
    {
        foreach ($parts as $part) {
            if (! preg_match('/^data\/gis_[a-z_]+-\d+\.ndjson\.gz$/D', $part['file'])) {
                throw new RuntimeException('Некорректный путь seed.');
            }
            $stream = gzopen($directory.'/'.$part['file'], 'rb');
            if ($stream === false) {
                throw new RuntimeException('Не удалось прочитать seed.');
            }
            $count = 0;
            try {
                while (($line = gzgets($stream)) !== false) {
                    $count++;
                    yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                }
            } finally {
                gzclose($stream);
            }
            if ($count !== $part['rows']) {
                throw new RuntimeException('Число записей seed не совпадает с манифестом.');
            }
        }
    }

    public function restore(string $path): bool
    {
        if (GisLayer::query()->whereNull('user_id')->exists()) {
            return false;
        }
        if (! is_file($path.'/manifest.json')) {
            throw new RuntimeException('Отсутствует локальный GIS seed: '.$path);
        }
        $manifest = json_decode(File::get($path.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? null) !== 1 || array_keys($manifest['tables']) !== self::TABLES) {
            throw new RuntimeException('Неподдерживаемый формат GIS seed.');
        }
        foreach ($manifest['tables'] as $parts) {
            foreach ($parts as $part) {
                if (! preg_match('/^data\/gis_[a-z_]+-\d+\.ndjson\.gz$/D', $part['file']) || ! is_file($path.'/'.$part['file']) || hash_file('sha256', $path.'/'.$part['file']) !== $part['sha256']) {
                    throw new RuntimeException('Проверка целостности GIS seed не пройдена.');
                }
            }
        }
        foreach ($manifest['assets'] as $hash => $bytes) {
            if (! preg_match('/^[a-f0-9]{64}$/D', $hash) || ! is_file($path.'/blobs/'.$hash) || filesize($path.'/blobs/'.$hash) !== $bytes || hash_file('sha256', $path.'/blobs/'.$hash) !== $hash) {
                throw new RuntimeException('Проверка целостности файла GIS не пройдена.');
            }
        }
        $disk = Storage::disk(config('gis.disk'));
        foreach ($manifest['assets'] as $hash => $bytes) {
            $stream = fopen($path.'/blobs/'.$hash, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Не удалось прочитать файл GIS.');
            }
            try {
                if (! $disk->put('gis/blobs/'.substr($hash, 0, 2).'/'.$hash, $stream)) {
                    throw new RuntimeException('Не удалось восстановить файл GIS.');
                }
            } finally {
                fclose($stream);
            }
        }

        return DB::transaction(function () use ($path, $manifest): bool {
            DB::statement('LOCK TABLE gis_layers, gis_layer_versions, gis_feature_contents IN SHARE ROW EXCLUSIVE MODE');
            if (GisLayer::query()->whereNull('user_id')->exists()) {
                return false;
            }
            $versionOffset = (int) DB::table('gis_layer_versions')->max('id');
            $featureOffset = (int) DB::table('gis_feature_contents')->max('id');
            foreach (self::TABLES as $table) {
                $batch = [];
                $size = 0;
                foreach ($this->readRows($path, $manifest['tables'][$table]) as $row) {
                    if ($table === 'gis_layers') {
                        $row['user_id'] = null;
                        $row['active_version_id'] = $row['active_version_id'] ? $row['active_version_id'] + $versionOffset : null;
                    }
                    if ($table === 'gis_layer_versions') {
                        $row['id'] += $versionOffset;
                        $row['author_id'] = null;
                    }
                    if ($table === 'gis_feature_contents') {
                        $row['id'] += $featureOffset;
                    }
                    if ($table === 'gis_version_features') {
                        $row['feature_id'] += $featureOffset;
                    }
                    if (isset($row['version_id'])) {
                        $row['version_id'] += $versionOffset;
                    }
                    if ($table === 'gis_version_assets') {
                        unset($row['id']);
                    }
                    if ($table === 'gis_assets') {
                        $row['path'] = 'gis/blobs/'.substr($row['hash'], 0, 2).'/'.$row['hash'];
                    }
                    $batch[] = $row;
                    $size += strlen(json_encode($row, JSON_THROW_ON_ERROR));
                    if (count($batch) >= 500 || $size >= 8 * 1024 * 1024) {
                        $this->insert($table, $batch);
                        $batch = [];
                        $size = 0;
                    }
                }
                $this->insert($table, $batch);
            }
            foreach (['gis_layer_versions', 'gis_feature_contents'] as $table) {
                DB::selectOne("SELECT setval(pg_get_serial_sequence(?, 'id'), GREATEST(COALESCE((SELECT MAX(id) FROM $table), 1), (SELECT last_value FROM {$table}_id_seq)), true)", [$table]);
            }

            return true;
        });
    }

    /**
     * Restore native HEXEWKB verbatim, including invalid source rings that
     * ST_GeomFromEWKB rejects. Editing personal features has separate validation.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function insert(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        if (in_array($table, ['gis_feature_contents', 'gis_version_features', 'gis_version_assets'], true)) {
            $pdo = DB::connection()->getPdo();
            if (! $pdo instanceof Pgsql) {
                throw new RuntimeException('Для GIS seed требуется PHP 8.4+ с PDO PostgreSQL.');
            }
            $fields = match ($table) {
                'gis_feature_contents' => ['id', 'gis_layer_id', 'source_id', 'hash', 'properties', 'raw', 'geometry', 'created_at'],
                'gis_version_features' => ['version_id', 'feature_id', 'source_id'],
                'gis_version_assets' => ['version_id', 'asset_hash', 'name', 'name_hash', 'feature_source_id'],
            };
            $lines = [];
            foreach ($rows as $row) {
                $values = [];
                foreach ($fields as $field) {
                    $values[] = $row[$field] === null ? '\\N' : strtr((string) $row[$field], ['\\' => '\\\\', "\t" => '\\t', "\n" => '\\n', "\r" => '\\r']);
                }
                $lines[] = implode("\t", $values);
            }
            if (! $pdo->copyFromArray($table, $lines, fields: implode(',', $fields))) {
                throw new RuntimeException('Не удалось восстановить таблицу GIS seed: '.$table);
            }
        } elseif (in_array($table, ['gis_assets', 'gis_imports'], true)) {
            DB::table($table)->insertOrIgnore($rows);
        } else {
            DB::table($table)->insert($rows);
        }
    }
}
