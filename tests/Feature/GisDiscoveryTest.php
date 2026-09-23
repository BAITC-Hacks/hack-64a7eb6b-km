<?php

namespace Tests\Feature;

use App\Actions\Gis\DiscoverGisLayers;
use App\Actions\Gis\GisArchive;
use App\Actions\Gis\GisFeatures;
use App\Actions\Gis\GisTiles;
use App\Actions\Gis\ImportGisLayer;
use App\Models\GisLayer;
use App\Models\GisLayerVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GisDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_includes_disabled_groups_all_service_leaves_and_inline_features_once(): void
    {
        Storage::fake('local');
        config(['gis.maps' => ['first', 'second']]);
        $root = 'https://gis.esaulet.kz/server/rest/services/Test/MapServer';
        Http::fake(function (Request $request) use ($root) {
            $url = explode('?', $request->url())[0];
            if (str_ends_with($url, '/data')) {
                return Http::response(['operationalLayers' => [['id' => 'group', 'title' => 'Группа', 'visibility' => false, 'layers' => [['id' => 'source', 'title' => 'Источник', 'url' => $root.'/1', 'layers' => [['id' => 1]]], ['id' => 'inline', 'title' => 'Улицы', 'featureCollection' => ['layers' => [['layerDefinition' => ['name' => 'Улицы'], 'featureSet' => ['features' => []]]]]]]]]]);
            }
            if ($url === $root.'/layers') {
                return Http::response(['layers' => [['id' => 0, 'name' => 'Root', 'subLayers' => [['id' => 1], ['id' => 2]]], ['id' => 1, 'name' => 'Здания', 'geometryType' => 'esriGeometryPolygon'], ['id' => 2, 'name' => 'Скрытый слой', 'geometryType' => 'esriGeometryPoint']], 'tables' => [['id' => 3, 'name' => 'Справочник']]]);
            }

            return Http::response(['title' => 'Исходная карта', 'licenseInfo' => 'Attribution']);
        });
        $run = app(DiscoverGisLayers::class)->handle();
        $this->assertSame('running', $run->status);
        app(DiscoverGisLayers::class)->ensureManifest($run);
        $this->assertDatabaseCount('gis_layers', 5);
        $hidden = GisLayer::query()->where('source_url', $root.'/2')->firstOrFail();
        $this->assertCount(2, $hidden->occurrences);
        $this->assertSame(['Группа'], $hidden->occurrences[0]['groups']);
        $this->assertSame('table', GisLayer::query()->where('source_url', $root.'/3')->firstOrFail()->kind);
        $this->assertSame($run->id, app(DiscoverGisLayers::class)->handle()->id);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), $root.'/layers')));
    }

    public function test_geojson_failure_uses_wgs84_esri_geometry_without_losing_disjoint_polygons_or_holes(): void
    {
        Storage::fake('local');
        $layer = GisLayer::factory()->create(['source_url' => 'https://gis.esaulet.kz/server/rest/services/Test/MapServer/0']);
        $version = GisLayerVersion::query()->create(['gis_layer_id' => $layer->id, 'observed_at' => now(), 'metadata' => [], 'cursor' => []]);
        $rings = [[[71, 51], [72, 51], [72, 52], [71, 52], [71, 51]], [[71.2, 51.2], [71.8, 51.2], [71.8, 51.8], [71.2, 51.8], [71.2, 51.2]], [[73, 51], [74, 51], [74, 52], [73, 52], [73, 51]]];
        Http::fake(function (Request $request) use ($rings) {
            if (($request['returnIdsOnly'] ?? null) === 'true') {
                return Http::response(['objectIds' => [1], 'objectIdFieldName' => 'OBJECTID']);
            }
            if ($request['f'] === 'geojson') {
                return Http::response(['error' => ['code' => 400]]);
            }

            return Http::response(['features' => [['attributes' => ['OBJECTID' => 1], 'geometry' => ['rings' => $rings]]]]);
        });
        $this->assertFalse(app(ImportGisLayer::class)->step($version));
        $this->assertTrue(app(ImportGisLayer::class)->step($version->refresh()));
        $geometry = DB::selectOne('SELECT ST_Area(geometry) AS area, ST_NumGeometries(geometry) AS parts, ST_IsValid(geometry) AS valid FROM gis_feature_contents');
        $this->assertTrue($geometry->valid);
        $this->assertSame(2, $geometry->parts);
        $this->assertEqualsWithDelta(1.64, $geometry->area, 0.00001);
        $this->assertTrue(app(GisArchive::class)->exists($version, 'normalized/page-0.json.gz'));
    }

    public function test_timeout_leaves_cursor_and_published_snapshot_available_for_resume(): void
    {
        Storage::fake('local');
        $layer = GisLayer::factory()->create(['source_url' => 'https://gis.esaulet.kz/server/rest/services/Test/MapServer/0']);
        $version = GisLayerVersion::query()->create(['gis_layer_id' => $layer->id, 'observed_at' => now(), 'metadata' => [], 'cursor' => ['offset' => 0, 'oid' => 'OBJECTID']]);
        app(GisArchive::class)->json($version, 'object-ids.json.gz', [1]);
        $version->update(['expected_count' => 1]);
        Http::fake(['*' => Http::failedConnection()]);
        try {
            app(ImportGisLayer::class)->step($version);
            $this->fail('Timeout must interrupt the job.');
        } catch (ConnectionException) {
            $this->assertSame(0, $version->fresh()->cursor['offset']);
            $this->assertNull($version->fresh()->published_at);
            $this->assertSame('building', $version->fresh()->status);
        }
    }

    public function test_large_esri_polygon_preserves_holes_and_disjoint_parts(): void
    {
        $rings = [[[0, 0], [100, 0], [100, 100], [0, 100], [0, 0]]];
        for ($i = 0; $i < 120; $i++) {
            $x = ($i % 12) * 4 + 1;
            $y = intdiv($i, 12) * 4 + 1;
            $rings[] = [[$x, $y], [$x + 1, $y], [$x + 1, $y + 1], [$x, $y + 1], [$x, $y]];
        }
        $rings[] = [[110, 0], [111, 0], [111, 1], [110, 1], [110, 0]];
        $geometry = app(GisFeatures::class)->fromEsri(['rings' => $rings]);
        $result = DB::selectOne('SELECT ST_Area(g) AS area, ST_NumGeometries(g) AS parts, ST_IsValid(g) AS valid FROM (SELECT ST_GeomFromGeoJSON(?) AS g) data', [json_encode($geometry, JSON_THROW_ON_ERROR)]);
        $this->assertEqualsWithDelta(9881, $result->area, 0.00001);
        $this->assertSame(2, $result->parts);
        $this->assertTrue($result->valid);
    }

    public function test_missing_vector_tiles_are_archived_as_absent_and_never_requested_live(): void
    {
        Storage::fake('local');
        $layer = GisLayer::factory()->create(['kind' => 'vector_tile', 'source_url' => 'https://gis.esaulet.kz/server/rest/services/Test/VectorTileServer']);
        $version = GisLayerVersion::query()->create(['gis_layer_id' => $layer->id, 'observed_at' => now(), 'metadata' => [], 'cursor' => ['resources' => true, 'z' => 0], 'coverage' => ['bbox3857' => [0, 0, 1, 1], 'max_zoom' => 0, 'padding_m' => 1000]]);
        app(GisArchive::class)->json($version, 'tile-plan.json.gz', [[0, 0, 0]]);
        Http::fake(['*/tilemap/*' => Http::response(['error' => ['code' => 422, 'message' => 'Tiles not present']])]);
        $this->assertTrue(app(GisTiles::class)->step($version));
        $this->assertSame('', app(GisArchive::class)->read($version, 'tiles/0/0/0.pbf'));
        Http::assertSentCount(1);
        app(GisFeatures::class)->publish($version);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->get(route('gis.tiles', ['layer' => $layer->id, 'version' => $version->id, 'z' => 0, 'x' => 0, 'y' => 0, 'format' => 'pbf']))->assertOk()->assertContent('');
        Http::assertNothingSent();
    }
}
