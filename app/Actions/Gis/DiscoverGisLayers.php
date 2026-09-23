<?php

namespace App\Actions\Gis;

use App\Models\GisImport;
use App\Models\GisLayer;
use App\Models\GisLayerVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DiscoverGisLayers
{
    /** @var array<string, list<GisLayer>> */
    private array $services = [];

    public function __construct(private ArcGisClient $client, private GisArchive $archive) {}

    public function handle(): GisImport
    {
        return Cache::lock('gis:discovery', 1800)->block(2, function (): GisImport {
            $existing = GisImport::query()->whereIn('status', ['discovering', 'running'])->latest()->first();
            if ($existing && $existing->status === 'running') {
                return $existing;
            }
            $run = $existing ?? GisImport::query()->create(['status' => 'discovering', 'errors' => []]);
            $errors = [];
            $this->services = [];
            foreach (config('gis.maps') as $mapId) {
                try {
                    $base = config('gis.portal').'/sharing/rest/content/items/'.$mapId;
                    $item = $this->client->json($base);
                    $map = $this->client->json($base.'/data');
                    $this->recordMap($run, $mapId, $item, $map);
                    foreach (array_merge($map['operationalLayers'] ?? [], $map['baseMap']['baseMapLayers'] ?? []) as $entry) {
                        $this->visit($entry, $mapId, $item['title'] ?? $mapId, [], $run, $errors);
                    }
                } catch (Throwable $error) {
                    $errors[] = ['source' => $mapId, 'error' => $this->safeError($error)];
                }
            }
            foreach (GisLayerVersion::query()->where('gis_import_id', $run->id)->where('status', 'building')->with('layer')->get() as $version) {
                $version->update(['metadata' => $version->metadata + ['occurrences' => $version->layer->occurrences, 'maps' => $run->manifest]]);
            }
            $run->update(['status' => 'running', 'errors' => $errors]);
            $run->update(['status' => GisLayerVersion::query()->where('gis_import_id', $run->id)->exists() ? 'running' : 'failed']);

            return $run;
        });
    }

    /** @param array<string, mixed> $entry
     * @param  list<string>  $parents
     * @param  list<array<string, mixed>>  $errors
     */
    private function visit(array $entry, string $mapId, string $mapTitle, array $parents, GisImport $run, array &$errors): void
    {
        $title = $entry['title'] ?? $entry['id'] ?? 'Слой';
        $occurrence = ['map_id' => $mapId, 'map_title' => $mapTitle, 'title' => $title, 'groups' => $parents, 'presentation' => array_diff_key($entry, ['featureCollection' => true, 'layers' => true])];
        if (isset($entry['featureCollection'])) {
            foreach ($entry['featureCollection']['layers'] as $index => $inline) {
                $this->register('inline:'.$mapId.':'.$entry['id'].':'.$index, null, $title, 'vector', ['layer' => $inline['layerDefinition'], 'inline' => $inline['featureSet']], $occurrence, $run);
            }

            return;
        }
        $url = $entry['url'] ?? (isset($entry['styleUrl']) ? preg_replace('~/resources/styles/.*$~', '', $entry['styleUrl']) : null);
        if ($url) {
            try {
                $url = rtrim($this->client->url($url), '/');
                if (str_contains($url, '/VectorTileServer')) {
                    $metadata = $this->client->json($url);
                    $this->register($url, $url, $title, 'vector_tile', ['service' => $metadata], $occurrence, $run);
                } else {
                    $root = preg_replace('~/(MapServer|FeatureServer)/\d+$~', '/$1', $url);
                    if (! isset($this->services[$root])) {
                        $service = $this->client->json($root);
                        $layers = $this->client->json($root.'/layers');
                        $this->services[$root] = [];
                        $legend = str_contains($root, '/MapServer') ? $this->client->json($root.'/legend') : [];
                        foreach (array_merge($layers['layers'] ?? [], $layers['tables'] ?? []) as $definition) {
                            if (! empty($definition['subLayers'])) {
                                continue;
                            }
                            $layerUrl = $root.'/'.$definition['id'];
                            $kind = ($definition['type'] ?? '') === 'Raster Layer' ? 'raster' : (empty($definition['geometryType']) ? 'table' : 'vector');
                            $layer = $this->register($layerUrl, $layerUrl, $definition['name'], $kind, ['layer' => $definition, 'service' => $service, 'legend' => $legend, 'service_url' => $root], $occurrence, $run);
                            $this->services[$root][] = $layer;
                        }
                    }
                    foreach ($this->services[$root] as $layer) {
                        $this->occurrence($layer, $occurrence);
                    }
                }
            } catch (Throwable $error) {
                $message = $this->safeError($error);
                $errors[] = ['source' => $url, 'error' => $message];
                $unavailable = GisLayer::query()->firstOrCreate(['source_key' => hash('sha256', $url)], ['source_url' => $url, 'title' => $title, 'kind' => 'unavailable', 'metadata' => [], 'occurrences' => [$occurrence]]);
                $unavailable->update(['status' => 'unavailable', 'error' => $message, 'checked_at' => now()]);
            }

            return;
        }
        foreach ($entry['layers'] ?? [] as $child) {
            $this->visit($child, $mapId, $mapTitle, [...$parents, $title], $run, $errors);
        }
    }

    /** @param array<string, mixed> $metadata
     * @param  array<string, mixed>  $occurrence
     */
    private function register(string $key, ?string $url, string $title, string $kind, array $metadata, array $occurrence, GisImport $run): GisLayer
    {
        $layer = GisLayer::query()->firstOrCreate(['source_key' => hash('sha256', $key)], ['source_url' => $url, 'title' => $title, 'kind' => $kind, 'metadata' => $metadata, 'occurrences' => []]);
        $layer->update(['metadata' => $metadata, 'kind' => $kind, 'checked_at' => now(), 'error' => null]);
        $this->occurrence($layer, $occurrence);
        GisLayerVersion::query()->firstOrCreate(['gis_layer_id' => $layer->id, 'gis_import_id' => $run->id], ['metadata' => $metadata, 'observed_at' => now(), 'cursor' => [], 'status' => 'building']);

        return $layer;
    }

    /** @param array<string, mixed> $entry */
    private function occurrence(GisLayer $layer, array $entry): void
    {
        $occurrences = $layer->occurrences ?? [];
        $key = $entry['map_id'].'|'.$entry['title'];
        $occurrences = array_values(array_filter($occurrences, fn (array $value): bool => $key !== $value['map_id'].'|'.$value['title']));
        $occurrences[] = $entry;
        $layer->update(['occurrences' => $occurrences]);
    }

    public function ensureManifest(GisImport $run): void
    {
        foreach (config('gis.maps') as $mapId) {
            if (! isset($run->manifest[$mapId])) {
                $base = config('gis.portal').'/sharing/rest/content/items/'.$mapId;
                $this->recordMap($run, $mapId, $this->client->json($base), $this->client->json($base.'/data'));
            }
            $map = json_decode($this->archive->readBlob($run->manifest[$mapId]['data_hash']), true, flags: JSON_THROW_ON_ERROR);
            $this->recordUnsupported(array_values(array_merge($map['operationalLayers'] ?? [], $map['baseMap']['baseMapLayers'] ?? [])), $mapId, $run->manifest[$mapId]['title']);
        }
    }

    /** @param list<array<string, mixed>> $entries
     * @param  list<string>  $parents
     */
    private function recordUnsupported(array $entries, string $mapId, string $mapTitle, array $parents = []): void
    {
        foreach ($entries as $entry) {
            $title = $entry['title'] ?? $entry['id'] ?? $entry['layerType'] ?? 'Слой без адреса';
            if (! empty($entry['url']) || ! empty($entry['styleUrl'])) {
                continue;
            } elseif (isset($entry['layers'])) {
                $this->recordUnsupported($entry['layers'], $mapId, $mapTitle, [...$parents, $title]);
            } elseif (empty($entry['url']) && empty($entry['styleUrl']) && empty($entry['featureCollection'])) {
                $occurrence = ['map_id' => $mapId, 'map_title' => $mapTitle, 'title' => $title, 'groups' => $parents, 'presentation' => $entry];
                $layer = GisLayer::query()->firstOrCreate(['source_key' => hash('sha256', 'unsupported:'.$mapId.':'.($entry['id'] ?? $title))], ['title' => $title, 'kind' => 'unavailable', 'metadata' => $entry, 'occurrences' => []]);
                $this->occurrence($layer, $occurrence);
                $layer->update(['status' => 'unavailable', 'error' => 'Этот слой не предоставляет адрес ArcGIS-сервиса; локальный архив отсутствует.', 'checked_at' => now()]);
            }
        }
    }

    /** @param array<string, mixed> $item
     * @param  array<string, mixed>  $map
     */
    private function recordMap(GisImport $run, string $mapId, array $item, array $map): void
    {
        $manifest = $run->manifest ?? [];
        $manifest[$mapId] = ['title' => $item['title'] ?? $mapId, 'license' => $item['licenseInfo'] ?? null, 'attribution' => $item['accessInformation'] ?? null, 'observed_at' => now()->toISOString(), 'item_hash' => $this->archive->blob(json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'application/json'), 'data_hash' => $this->archive->blob(json_encode($map, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'application/json')];
        $run->update(['manifest' => $manifest]);
        $this->recordUnsupported(array_values(array_merge($map['operationalLayers'] ?? [], $map['baseMap']['baseMapLayers'] ?? [])), $mapId, $manifest[$mapId]['title']);
    }

    public function safeError(Throwable $error): string
    {
        return $error instanceof \RuntimeException && ! $error instanceof QueryException
            ? mb_substr($error->getMessage(), 0, 200) : 'Ошибка загрузки: '.class_basename($error);
    }
}
