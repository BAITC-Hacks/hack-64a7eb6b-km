<?php

namespace App\Actions\Gis;

use App\Models\GisLayerVersion;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class GisFeatures
{
    /** @param array<string, mixed> $properties
     * @param  array<string, mixed>|null  $geometry
     * @param  array<string, mixed>|null  $raw
     */
    public function put(GisLayerVersion $version, string $sourceId, array $properties, ?array $geometry, ?array $raw = null, int $srid = 4326): int
    {
        $hash = hash('sha256', $this->canonical([$properties, $geometry, $raw]));
        $existing = DB::table('gis_feature_contents')->where('gis_layer_id', $version->gis_layer_id)->where('source_id', $sourceId)->where('hash', $hash)->value('id');
        if (! $existing) {
            $rows = DB::select('INSERT INTO gis_feature_contents (gis_layer_id, source_id, hash, properties, raw, geometry, created_at) VALUES (?, ?, ?, ?::jsonb, ?::jsonb, ST_Force2D(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?), ?), 4326)), ?) ON CONFLICT (gis_layer_id, source_id, hash) DO UPDATE SET hash = EXCLUDED.hash RETURNING id', [
                $version->gis_layer_id, $sourceId, $hash, json_encode($properties, JSON_THROW_ON_ERROR), json_encode($raw, JSON_THROW_ON_ERROR), $geometry ? json_encode($geometry, JSON_THROW_ON_ERROR) : null, $srid, now(),
            ]);
            $existing = $rows[0]->id;
        }
        DB::table('gis_version_features')->updateOrInsert(['version_id' => $version->id, 'source_id' => $sourceId], ['feature_id' => $existing]);

        return (int) $existing;
    }

    /** @param list<array{source_id: string, properties: array<string, mixed>, geometry: array<string, mixed>|null, raw: array<string, mixed>}> $features */
    public function putBatch(GisLayerVersion $version, array $features, int $srid = 4326): void
    {
        if ($features === []) {
            return;
        }
        $rows = array_map(fn (array $feature): array => $feature + ['hash' => hash('sha256', $this->canonical([$feature['properties'], $feature['geometry'], $feature['raw']]))], $features);
        DB::statement("WITH incoming AS (SELECT * FROM jsonb_to_recordset(?::jsonb) AS r(source_id text, hash text, properties jsonb, geometry jsonb, raw jsonb)), saved AS (INSERT INTO gis_feature_contents (gis_layer_id,source_id,hash,properties,raw,geometry,created_at) SELECT ?,source_id,hash,properties,raw,ST_Force2D(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(NULLIF(geometry,'null'::jsonb)::text),?),4326)),? FROM incoming ON CONFLICT (gis_layer_id,source_id,hash) DO NOTHING RETURNING id,source_id), members AS (SELECT id,source_id FROM saved UNION ALL SELECT f.id,f.source_id FROM gis_feature_contents f JOIN incoming i ON i.source_id=f.source_id AND i.hash=f.hash WHERE f.gis_layer_id=?) INSERT INTO gis_version_features (version_id,feature_id,source_id) SELECT ?,id,source_id FROM members ON CONFLICT (version_id,source_id) DO UPDATE SET feature_id=EXCLUDED.feature_id", [json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $version->gis_layer_id, $srid, now(), $version->gis_layer_id, $version->id]);
    }

    public function query(GisLayerVersion $version): Builder
    {
        return DB::table('gis_version_features as vf')->join('gis_feature_contents as f', 'f.id', '=', 'vf.feature_id')->where('vf.version_id', $version->id);
    }

    /** @return array<string, mixed> */
    public function feature(\stdClass $row): array
    {
        return ['type' => 'Feature', 'id' => $row->source_id, 'properties' => json_decode($row->properties, true), 'geometry' => $row->geojson ? json_decode($row->geojson, true) : null, 'content_id' => $row->id];
    }

    public function publish(GisLayerVersion $version): void
    {
        DB::transaction(function () use ($version): void {
            $layer = $version->layer()->lockForUpdate()->firstOrFail();
            $version->refresh();
            if (in_array($version->status, ['published', 'seeded'], true)) {
                return;
            }
            $count = $this->query($version)->count();
            $coverage = $version->coverage;
            if (in_array($layer->kind, ['vector', 'custom'])) {
                $extent = DB::selectOne('SELECT ST_XMin(b) AS west, ST_YMin(b) AS south, ST_XMax(b) AS east, ST_YMax(b) AS north FROM (SELECT ST_Extent(f.geometry) AS b FROM gis_version_features vf JOIN gis_feature_contents f ON f.id=vf.feature_id WHERE vf.version_id=?) s', [$version->id]);
                $coverage = ['bbox4326' => $extent?->west !== null ? [(float) $extent->west, (float) $extent->south, (float) $extent->east, (float) $extent->north] : null];
            }
            $version->update(['status' => 'published', 'feature_count' => $count, 'published_at' => now(), 'coverage' => $coverage, 'error' => null]);
            $layer->update(['active_version_id' => $version->id, 'status' => 'ready', 'error' => null]);
        });
    }

    public function canonical(mixed $value): string
    {
        return json_encode($this->sortKeys($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function sortKeys(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $this->sortKeys($item);
                }
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $geometry
     * @return array<string, mixed>|null
     */
    public function fromEsri(array $geometry): ?array
    {
        if (isset($geometry['x'], $geometry['y'])) {
            return ['type' => 'Point', 'coordinates' => [$geometry['x'], $geometry['y']]];
        }
        if (isset($geometry['paths'])) {
            return ['type' => 'MultiLineString', 'coordinates' => $geometry['paths']];
        }
        if (isset($geometry['rings'])) {
            return $this->polygon($geometry['rings']);
        }
        if (isset($geometry['points'])) {
            return ['type' => 'MultiPoint', 'coordinates' => $geometry['points']];
        }

        return null;
    }

    /** @param list<list<list<float>>> $rings
     * @return array<string, mixed>|null
     */
    private function polygon(array $rings): ?array
    {
        if ($rings === []) {
            return null;
        }
        if (count($rings) > 100 || array_sum(array_map(count(...), $rings)) > 10000) {
            $row = DB::selectOne('SELECT ST_AsGeoJSON(ST_BuildArea(ST_GeomFromGeoJSON(?)), 15) AS geometry', [json_encode(['type' => 'MultiLineString', 'coordinates' => $rings], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]);

            return $row?->geometry ? json_decode($row->geometry, true, flags: JSON_THROW_ON_ERROR) : null;
        }
        $area = function (array $ring): float {
            $sum = 0.0;
            for ($i = 1; $i < count($ring); $i++) {
                $sum += $ring[$i - 1][0] * $ring[$i][1] - $ring[$i][0] * $ring[$i - 1][1];
            }

            return abs($sum);
        };
        usort($rings, fn (array $left, array $right): int => $area($right) <=> $area($left));
        $parents = [];
        $polygons = [];
        $owners = [];
        foreach ($rings as $index => $ring) {
            $depth = 0;
            $parent = null;
            for ($previous = $index - 1; $previous >= 0; $previous--) {
                if ($this->insideRing($ring[0], $rings[$previous])) {
                    $parent = $previous;
                    $depth = $parents[$previous] + 1;
                    break;
                }
            }
            $parents[$index] = $depth;
            if ($depth % 2 === 0 || $parent === null) {
                $owners[$index] = count($polygons);
                $polygons[] = [$ring];
            } else {
                $owners[$index] = $owners[$parent];
                $polygons[$owners[$index]][] = $ring;
            }
        }

        return count($polygons) === 1 ? ['type' => 'Polygon', 'coordinates' => $polygons[0]] : ['type' => 'MultiPolygon', 'coordinates' => $polygons];
    }

    /** @param list<float> $point
     * @param  list<list<float>>  $ring
     */
    private function insideRing(array $point, array $ring): bool
    {
        $inside = false;
        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            if (($ring[$i][1] > $point[1]) !== ($ring[$j][1] > $point[1]) && $point[0] < ($ring[$j][0] - $ring[$i][0]) * ($point[1] - $ring[$i][1]) / ($ring[$j][1] - $ring[$i][1]) + $ring[$i][0]) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
