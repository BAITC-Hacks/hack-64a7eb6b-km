<?php

namespace App\Actions\Gis;

use App\Models\GisLayerVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportGisLayer
{
    public function __construct(private ArcGisClient $client, private GisArchive $archive, private GisFeatures $features, private GisTiles $tiles) {}

    public function step(GisLayerVersion $version): bool
    {
        if (in_array($version->status, ['published', 'seeded'], true)) {
            return true;
        }
        $layer = $version->layer;
        $version->update(['status' => 'building', 'error' => null]);
        if (in_array($layer->kind, ['raster', 'vector_tile'])) {
            if (! $this->tiles->step($version)) {
                return false;
            }
            $this->features->publish($version);

            return true;
        }
        $metadata = $version->metadata;
        $cursor = $version->cursor;
        $definition = $metadata['layer'] ?? [];
        if (isset($metadata['inline'])) {
            $inline = $metadata['inline'];
            $offset = (int) ($cursor['offset'] ?? 0);
            $chunk = array_slice($inline['features'] ?? [], $offset, (int) config('gis.page_size'));
            $oid = $this->oid($definition);
            $srid = (int) ($inline['spatialReference']['latestWkid'] ?? $inline['spatialReference']['wkid'] ?? 4326);
            $srid = $srid === 102100 ? 3857 : $srid;
            DB::transaction(function () use ($chunk, $version, $oid, $srid, $offset): void {
                foreach ($chunk as $index => $feature) {
                    $this->features->put($version, (string) ($feature['attributes'][$oid] ?? $offset + $index), $feature['attributes'] ?? [], $this->features->fromEsri($feature['geometry'] ?? []), $feature, $srid);
                }
            });
            $this->archive->json($version, 'raw/inline-'.$offset.'.json.gz', $chunk);
            $offset += count($chunk);
            $version->update(['cursor' => ['offset' => $offset], 'expected_count' => count($inline['features'] ?? [])]);
            if ($offset < $version->expected_count) {
                return false;
            }
            $this->features->publish($version);

            return true;
        }
        if (! $this->archive->exists($version, 'object-ids.json.gz')) {
            $result = $this->client->json($layer->source_url.'/query', ['where' => '1=1', 'returnIdsOnly' => 'true']);
            if (! array_key_exists('objectIds', $result)) {
                throw new RuntimeException('Источник не вернул перечень objectIds.');
            }
            $ids = $result['objectIds'] ?? [];
            if (count($ids) !== count(array_unique($ids))) {
                throw new RuntimeException('Источник вернул повторяющиеся objectIds.');
            }
            sort($ids, SORT_NUMERIC);
            $this->archive->json($version, 'object-ids.json.gz', $ids);
            $this->archive->json($version, 'metadata.json.gz', $metadata);
            $cursor = ['offset' => 0, 'oid' => $result['objectIdFieldName'] ?? $this->oid($definition)];
            $version->update(['expected_count' => count($ids), 'cursor' => $cursor]);
        }
        $ids = $this->archive->readJson($version, 'object-ids.json.gz');
        $offset = (int) ($cursor['offset'] ?? 0);
        $pageSize = min((int) config('gis.page_size'), (int) ($definition['maxRecordCount'] ?? 1000));
        $chunk = array_slice($ids, $offset, max(1, $pageSize));
        if ($chunk !== []) {
            $args = ['objectIds' => implode(',', $chunk), 'outFields' => '*', 'returnGeometry' => 'true', 'returnZ' => 'true', 'returnM' => 'true'];
            $rawName = 'raw/page-'.$offset.'.json.gz';
            $raw = $this->archive->exists($version, $rawName)
                ? $this->archive->readJson($version, $rawName)
                : $this->client->json($layer->source_url.'/query', $args + ['returnTrueCurves' => 'true']);
            $this->archive->json($version, $rawName, $raw);
            $geo = null;
            if ($layer->kind !== 'table') {
                $normalizedArgs = array_replace($args, ['outSR' => 4326, 'returnZ' => 'false', 'returnM' => 'false', 'returnTrueCurves' => 'false']);
                $normalizedName = 'normalized/page-'.$offset.'.json.gz';
                try {
                    if ($this->archive->exists($version, $normalizedName)) {
                        throw new RuntimeException('Используется сохранённая геометрия.', 400);
                    }
                    $geo = $this->client->json($layer->source_url.'/query', $normalizedArgs + ['f' => 'geojson']);
                } catch (RuntimeException $error) {
                    if ($error->getCode() !== 400) {
                        throw $error;
                    }
                    $normalized = $this->archive->exists($version, $normalizedName)
                        ? $this->archive->readJson($version, $normalizedName)
                        : $this->client->json($layer->source_url.'/query', $normalizedArgs);
                    $this->archive->json($version, $normalizedName, $normalized);
                    $geo = ['features' => array_map(fn (array $feature): array => ['properties' => $feature['attributes'], 'geometry' => $this->features->fromEsri($feature['geometry'] ?? [])], $normalized['features'] ?? [])];
                }
            }
            $oid = $cursor['oid'];
            $rawById = [];
            foreach ($raw['features'] ?? [] as $item) {
                $id = (string) ($item['attributes'][$oid] ?? '');
                if ($id === '' || isset($rawById[$id])) {
                    throw new RuntimeException('Некорректные идентификаторы в порции GIS.');
                }
                $rawById[$id] = $item;
            }
            $geometries = [];
            foreach ($geo['features'] ?? [] as $item) {
                $id = (string) ($item['properties'][$oid] ?? $item['id'] ?? '');
                if (array_key_exists($id, $geometries)) {
                    throw new RuntimeException('Повтор объекта в геометриях GIS.');
                }
                $geometries[$id] = $item['geometry'];
            }
            if (count($rawById) !== count($chunk) || ($geo !== null && count($geometries) !== count($chunk))) {
                throw new RuntimeException('Неполная порция: снимок не опубликован.');
            }
            foreach ($chunk as $id) {
                if (! isset($rawById[(string) $id]) || ($geo !== null && ! array_key_exists((string) $id, $geometries))) {
                    throw new RuntimeException('Запрошенный объект отсутствует в ответе GIS.');
                }
            }
            DB::transaction(function () use ($version, $chunk, $rawById, $geometries): void {
                $items = [];
                foreach ($chunk as $id) {
                    $item = $rawById[(string) $id];
                    $items[] = ['source_id' => (string) $id, 'properties' => $item['attributes'], 'geometry' => $geometries[(string) $id] ?? null, 'raw' => $item];
                }
                $this->features->putBatch($version, $items);
            });
            $offset += count($chunk);
            $cursor['offset'] = $offset;
            $version->update(['cursor' => $cursor, 'feature_count' => $this->features->query($version)->count()]);

            return false;
        }
        if ($this->features->query($version)->count() !== count($ids)) {
            throw new RuntimeException('Проверка полноты снимка не пройдена.');
        }
        if (! empty($definition['hasAttachments']) || ! empty($definition['relationships'])) {
            $extraOffset = (int) ($cursor['extras_offset'] ?? 0);
            $extraIds = array_slice($ids, $extraOffset, 10);
            foreach ($extraIds as $id) {
                if (! empty($definition['hasAttachments'])) {
                    $attachments = $this->client->json($layer->source_url.'/'.$id.'/attachments');
                    $this->archive->json($version, 'attachments/'.$id.'/index.json.gz', $attachments, (string) $id);
                    foreach ($attachments['attachmentInfos'] ?? [] as $attachment) {
                        $name = 'attachments/'.$id.'/'.$attachment['id'];
                        if (! $this->archive->exists($version, $name)) {
                            $response = $this->client->get($layer->source_url.'/'.$id.'/attachments/'.$attachment['id']);
                            $this->archive->put($version, $name, $response->body(), $response->header('Content-Type') ?: 'application/octet-stream', (string) $id);
                        }
                    }
                }
                foreach ($definition['relationships'] ?? [] as $relationship) {
                    $related = $this->client->json($layer->source_url.'/queryRelatedRecords', ['objectIds' => $id, 'relationshipId' => $relationship['id'], 'outFields' => '*', 'returnGeometry' => 'true']);
                    if (! empty($related['exceededTransferLimit'])) {
                        throw new RuntimeException('Связанные записи усечены; требуется отдельная выгрузка таблицы.');
                    }
                    $this->archive->json($version, 'related/'.$id.'/'.$relationship['id'].'.json.gz', $related, (string) $id);
                }
            }
            $cursor['extras_offset'] = $extraOffset + count($extraIds);
            $version->update(['cursor' => $cursor]);
            if ($cursor['extras_offset'] < count($ids)) {
                return false;
            }
        }
        $check = $this->client->json($layer->source_url.'/query', ['where' => '1=1', 'returnIdsOnly' => 'true']);
        $endIds = $check['objectIds'] ?? [];
        sort($endIds, SORT_NUMERIC);
        if ($ids !== $endIds) {
            throw new RuntimeException('Состав источника изменился во время загрузки; нужен новый снимок.');
        }
        $this->features->publish($version);

        return true;
    }

    /** @param array<string, mixed> $definition */
    private function oid(array $definition): string
    {
        foreach ($definition['fields'] ?? [] as $field) {
            if (($field['type'] ?? '') === 'esriFieldTypeOID') {
                return $field['name'];
            }
        }

        return $definition['objectIdField'] ?? 'OBJECTID';
    }
}
