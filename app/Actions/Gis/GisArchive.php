<?php

namespace App\Actions\Gis;

use App\Models\GisAsset;
use App\Models\GisLayerVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GisArchive
{
    public function put(GisLayerVersion $version, string $name, string $body, string $mime, ?string $featureId = null): string
    {
        if (in_array($version->status, ['published', 'seeded'], true)) {
            throw new RuntimeException('Опубликованный архив неизменяем.');
        }
        $hash = $this->blob($body, $mime);
        DB::table('gis_version_assets')->updateOrInsert(
            ['version_id' => $version->id, 'name_hash' => hash('sha256', $name)],
            ['name' => $name, 'asset_hash' => $hash, 'feature_source_id' => $featureId],
        );

        return $hash;
    }

    public function blob(string $body, string $mime): string
    {
        $hash = hash('sha256', $body);
        $path = 'gis/blobs/'.substr($hash, 0, 2).'/'.$hash;
        $disk = Storage::disk(config('gis.disk'));
        if (! $disk->exists($path) && ! $disk->put($path, $body)) {
            throw new RuntimeException('Не удалось сохранить файл GIS.');
        }
        GisAsset::query()->firstOrCreate(['hash' => $hash], ['path' => $path, 'mime' => $mime, 'bytes' => strlen($body), 'created_at' => now()]);

        return $hash;
    }

    public function readBlob(string $hash): string
    {
        $asset = GisAsset::query()->findOrFail($hash);

        return Storage::disk(config('gis.disk'))->get($asset->path);
    }

    /** @param array<string, string> $files */
    public function putMany(GisLayerVersion $version, array $files, string $mime): void
    {
        if (in_array($version->status, ['published', 'seeded'], true)) {
            throw new RuntimeException('Опубликованный архив неизменяем.');
        }
        $assets = [];
        $members = [];
        $disk = Storage::disk(config('gis.disk'));
        foreach ($files as $name => $body) {
            $hash = hash('sha256', $body);
            $path = 'gis/blobs/'.substr($hash, 0, 2).'/'.$hash;
            if (! isset($assets[$hash])) {
                if (! $disk->exists($path) && ! $disk->put($path, $body)) {
                    throw new RuntimeException('Не удалось сохранить файл GIS.');
                }
                $assets[$hash] = ['hash' => $hash, 'path' => $path, 'mime' => $mime, 'bytes' => strlen($body), 'created_at' => now()];
            }
            $members[] = ['version_id' => $version->id, 'name_hash' => hash('sha256', $name), 'name' => $name, 'asset_hash' => $hash, 'feature_source_id' => null];
        }
        if ($members === []) {
            return;
        }
        DB::transaction(function () use ($assets, $members): void {
            DB::table('gis_assets')->insertOrIgnore(array_values($assets));
            DB::table('gis_version_assets')->upsert($members, ['version_id', 'name_hash'], ['asset_hash', 'name']);
        });
    }

    /** @param array<mixed> $data */
    public function json(GisLayerVersion $version, string $name, array $data, ?string $featureId = null): string
    {
        $body = gzencode(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 6);
        if ($body === false) {
            throw new RuntimeException('Не удалось сжать GIS-данные.');
        }

        return $this->put($version, $name, $body, 'application/gzip', $featureId);
    }

    /** @return array<mixed> */
    public function readJson(GisLayerVersion $version, string $name): array
    {
        $body = gzdecode($this->read($version, $name));
        if ($body === false) {
            throw new RuntimeException('Повреждённый архив GIS.');
        }

        return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    }

    public function read(GisLayerVersion $version, string $name): string
    {
        $asset = DB::table('gis_version_assets as va')->join('gis_assets as a', 'a.hash', '=', 'va.asset_hash')
            ->where('va.version_id', $version->id)->where('va.name_hash', hash('sha256', $name))->first(['a.path']);
        if (! $asset) {
            throw new RuntimeException('Файл отсутствует в архиве.');
        }

        return Storage::disk(config('gis.disk'))->get($asset->path);
    }

    public function exists(GisLayerVersion $version, string $name): bool
    {
        return DB::table('gis_version_assets')->where('version_id', $version->id)->where('name_hash', hash('sha256', $name))->exists();
    }
}
