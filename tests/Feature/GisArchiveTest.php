<?php

namespace Tests\Feature;

use App\Actions\Gis\ArcGisClient;
use App\Actions\Gis\GisArchive;
use App\Actions\Gis\GisFeatures;
use App\Actions\Gis\ImportGisLayer;
use App\Models\GisLayer;
use App\Models\GisLayerVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class GisArchiveTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://gis.esaulet.kz/server/rest/services/Test/MapServer/0';

    private function layer(): GisLayer
    {
        return GisLayer::factory()->create(['source_url' => self::URL, 'metadata' => ['layer' => ['name' => 'Buildings', 'fields' => [['name' => 'OBJECTID', 'type' => 'esriFieldTypeOID']], 'maxRecordCount' => 1]]]);
    }

    private function snapshot(GisLayer $layer): GisLayerVersion
    {
        return GisLayerVersion::query()->create(['gis_layer_id' => $layer->id, 'metadata' => $layer->metadata, 'observed_at' => now(), 'cursor' => []]);
    }

    /** @param array<int, string> $objects */
    private function source(array $objects, bool $missing = false): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([self::URL.'/query' => function (Request $request) use ($objects, $missing) {
            if (($request['returnIdsOnly'] ?? null) === 'true') {
                return Http::response(['objectIdFieldName' => 'OBJECTID', 'objectIds' => array_keys($objects)]);
            }
            $ids = array_map('intval', explode(',', $request['objectIds']));
            $features = [];
            foreach ($ids as $id) {
                if ($missing) {
                    continue;
                }
                $attributes = ['OBJECTID' => $id, 'NAME' => $objects[$id]];
                $features[] = $request['f'] === 'geojson'
                    ? ['id' => $id, 'properties' => $attributes, 'geometry' => ['type' => 'Point', 'coordinates' => [71.4 + $id / 1000, 51.1]]]
                    : ['attributes' => $attributes, 'geometry' => ['x' => $id, 'y' => 10, 'z' => 4, 'm' => 2]];
            }

            return Http::response(['features' => $features]);
        }]);
    }

    private function finish(GisLayerVersion $version): void
    {
        for ($step = 0; $step < 15; $step++) {
            if (app(ImportGisLayer::class)->step($version->refresh())) {
                return;
            }
        }
        $this->fail('Import did not finish bounded fixture.');
    }

    public function test_import_is_resumable_complete_and_retains_native_geometry(): void
    {
        Storage::fake('local');
        $layer = $this->layer();
        $version = $this->snapshot($layer);
        $this->source([1 => 'Здание', 2 => 'Үй']);
        $this->assertFalse(app(ImportGisLayer::class)->step($version));
        $this->assertNull($layer->fresh()->active_version_id);
        $this->assertSame(1, $version->fresh()->cursor['offset']);
        $this->finish($version);
        $this->assertSame('published', $version->fresh()->status);
        $this->assertSame(2, $version->fresh()->feature_count);
        $raw = json_decode(DB::table('gis_feature_contents')->first()->raw, true);
        $this->assertSame(4, $raw['geometry']['z']);
        $this->assertSame(2, $raw['geometry']['m']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['f'] === 'geojson' && $request['returnM'] === 'false');
        $this->getJson(route('gis.features.index', ['layer' => $layer->id, 'version' => $version->id, 'limit' => 1]))->assertOk()->assertJsonPath('features.0.properties.NAME', 'Здание')->assertJsonPath('next', 1);
        $this->get(route('gis.tiles', ['layer' => $layer->id, 'version' => $version->id, 'z' => 10, 'x' => 715, 'y' => 342, 'format' => 'pbf']))->assertOk()->assertHeader('Content-Type', 'application/x-protobuf');
    }

    public function test_catalog_identifies_the_configured_district_source_instead_of_a_matching_title(): void
    {
        $districts = GisLayer::factory()->create(['title' => 'Границы районов', 'source_url' => config('gis.district_source')]);
        $unrelated = GisLayer::factory()->create(['title' => 'Районы', 'source_url' => self::URL]);

        $this->getJson(route('gis.layers.index'))
            ->assertJsonFragment(['id' => $districts->id, 'is_district_boundary' => true])
            ->assertJsonFragment(['id' => $unrelated->id, 'is_district_boundary' => false]);
    }

    public function test_failed_partial_import_does_not_remove_current_features(): void
    {
        Storage::fake('local');
        $layer = $this->layer();
        $first = $this->snapshot($layer);
        $this->source([1 => 'A']);
        $this->finish($first);
        $second = $this->snapshot($layer);
        $this->source([1 => 'A', 2 => 'B'], missing: true);
        try {
            $this->finish($second);
            $this->fail('Incomplete import was accepted.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Неполная порция', $error->getMessage());
        }
        $this->assertSame($first->id, $layer->fresh()->active_version_id);
        $this->assertNull($second->fresh()->published_at);
        $this->assertDatabaseCount('gis_feature_contents', 1);
    }

    public function test_content_is_deduplicated_and_date_queries_and_diff_are_correct(): void
    {
        Storage::fake('local');
        $this->travelTo(now()->startOfDay());
        $layer = $this->layer();
        $this->source([1 => 'A', 2 => 'B']);
        $first = $this->snapshot($layer);
        $this->finish($first);
        $date = now()->addHour()->toISOString();
        $this->travel(1)->days();
        $this->source([1 => 'A', 3 => 'C']);
        $second = $this->snapshot($layer);
        $this->finish($second);
        $this->assertDatabaseCount('gis_feature_contents', 3);
        $this->get(route('gis.difference', ['layer' => $layer->id, 'left' => $first->id, 'right' => $second->id, 'z' => 10, 'x' => 715, 'y' => 342]))->assertOk()->assertHeader('Content-Type', 'application/x-protobuf');
        $this->getJson(route('gis.layers.index', ['at' => $date]))->assertOk()->assertJsonPath('layers.0.version_id', $first->id);
        $this->getJson(route('gis.compare', ['layer' => $layer->id, 'left' => $first->id, 'right' => $second->id]))->assertOk()->assertJsonCount(2, 'changes')->assertJsonFragment(['change' => 'added'])->assertJsonFragment(['change' => 'removed']);
        $this->getJson(route('gis.layers.index', ['at' => now()->subYears(5)->toISOString()]))->assertJsonPath('layers.0.version_id', null);
    }

    public function test_empty_layer_is_a_valid_snapshot_and_duplicate_ids_are_rejected(): void
    {
        Storage::fake('local');
        $layer = $this->layer();
        $this->source([]);
        $empty = $this->snapshot($layer);
        $this->finish($empty);
        $this->assertSame(0, $empty->fresh()->feature_count);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([self::URL.'/query' => Http::response(['objectIdFieldName' => 'OBJECTID', 'objectIds' => [1, 1]])]);
        $this->expectExceptionMessage('повторяющиеся objectIds');
        app(ImportGisLayer::class)->step($this->snapshot($layer));
    }

    public function test_arcgis_error_inside_http_200_and_untrusted_urls_are_rejected(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([self::URL.'*' => Http::response(['error' => ['code' => 499, 'message' => 'secret provider diagnostic']])]);
        try {
            app(ArcGisClient::class)->json(self::URL);
            $this->fail('Error body accepted.');
        } catch (RuntimeException $error) {
            $this->assertSame('ArcGIS error 499', $error->getMessage());
        }
        $this->expectExceptionMessage('вне разрешённого');
        app(ArcGisClient::class)->json('https://example.com/server/rest/services/Test');
    }

    public function test_private_layers_are_isolated_and_optimistic_edits_have_history(): void
    {
        $owner = User::factory()->member()->create();
        $other = User::factory()->member()->create();
        $response = $this->actingAs($owner)->postJson(route('gis.layers.store'), ['title' => 'Мой слой', 'user_id' => $other->id])->assertCreated();
        $layer = GisLayer::query()->findOrFail($response->json('id'));
        $this->assertSame($owner->id, $layer->user_id);
        $initial = $layer->active_version_id;
        $payload = ['version_id' => $initial, 'geometry' => ['type' => 'Point', 'coordinates' => [71.4, 51.1]], 'properties' => ['title' => 'Школа', 'color' => '#008877', 'мән' => 42, 'детали' => ['язык' => 'Қазақша', 'секции' => ['a', 'b']], 'пусто' => null]];
        $created = $this->postJson(route('gis.features.store', $layer), $payload)->assertCreated()->json('version_id');
        $this->postJson(route('gis.features.store', $layer), $payload)->assertConflict();
        $content = DB::table('gis_feature_contents')->where('gis_layer_id', $layer->id)->first();
        $this->getJson(route('gis.features.show', ['layer' => $layer->id, 'feature' => $content->source_id, 'version' => $created]))->assertOk()->assertJsonPath('feature.properties.мән', 42)->assertJsonPath('feature.properties.детали.язык', 'Қазақша')->assertJsonPath('feature.properties.детали.секции', ['a', 'b'])->assertJsonPath('feature.properties.пусто', null);
        $this->actingAs($other)->getJson(route('gis.features.index', ['layer' => $layer->id, 'version' => $created]))->assertForbidden();
        $this->getJson(route('gis.features.history', ['layer' => $layer->id, 'feature' => $content->source_id]))->assertForbidden();
        $this->get(route('gis.tiles', ['layer' => $layer->id, 'version' => $created, 'z' => 10, 'x' => 715, 'y' => 342, 'format' => 'pbf']))->assertForbidden();
        $this->getJson(route('gis.layers.index'))->assertJsonCount(0, 'layers');
        $this->getJson(route('gis.export', ['layer' => $layer->id, 'version' => $created]))->assertForbidden();
        $this->getJson(route('gis.difference', ['layer' => $layer->id, 'left' => $initial, 'right' => $created, 'z' => 0, 'x' => 0, 'y' => 0]))->assertForbidden();
        $deleted = $this->actingAs($owner)->deleteJson(route('gis.features.destroy', ['layer' => $layer->id, 'feature' => $content->source_id]), ['version_id' => $created])->assertOk()->json('version_id');
        $this->postJson(route('gis.features.restore', ['layer' => $layer->id, 'feature' => $content->source_id]), ['version_id' => $deleted, 'content_id' => $content->id])->assertOk();
        $this->assertDatabaseCount('gis_feature_contents', 1);
        $this->getJson(route('gis.features.history', ['layer' => $layer->id, 'feature' => $content->source_id]))->assertOk()->assertJsonCount(4, 'data');
    }

    public function test_invalid_geometry_and_read_only_role_cannot_write(): void
    {
        $owner = User::factory()->member()->create();
        $response = $this->actingAs($owner)->postJson(route('gis.layers.store'), ['title' => 'Слой'])->assertCreated();
        $layer = GisLayer::query()->findOrFail($response->json('id'));
        $payload = ['version_id' => $layer->active_version_id, 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[71, 51], [72, 52], [71, 52], [72, 51], [71, 51]]]], 'properties' => ['title' => 'Некорректный полигон']];
        $this->postJson(route('gis.features.store', $layer), $payload)->assertUnprocessable()->assertJsonValidationErrors('geometry');
        $payload['geometry'] = ['type' => 'Point', 'coordinates' => [1000, 51]];
        $this->postJson(route('gis.features.store', $layer), $payload)->assertUnprocessable()->assertJsonValidationErrors('geometry');
        $this->assertDatabaseCount('gis_feature_contents', 0);
        $owner->syncRoles([]);
        $owner->givePermissionTo('workspace.view');
        $this->postJson(route('gis.layers.store'), ['title' => 'Запрещено'])->assertForbidden();
    }

    public function test_file_snapshots_are_immutable_and_deduplicate_bytes(): void
    {
        Storage::fake('local');
        $layer = $this->layer();
        $first = $this->snapshot($layer);
        $second = $this->snapshot($layer);
        $archive = app(GisArchive::class);
        $archive->put($first, 'tiles/0/0/0.png', 'old-image', 'image/png');
        $archive->put($second, 'tiles/0/0/0.png', 'new-image', 'image/png');
        $archive->put($second, 'tiles/1/0/0.png', 'old-image', 'image/png');
        $this->assertSame('old-image', $archive->read($first, 'tiles/0/0/0.png'));
        $this->assertDatabaseCount('gis_assets', 2);
        Http::assertNothingSent();
    }

    public function test_export_streams_full_snapshot_and_file_history_survives_source_outage(): void
    {
        Storage::fake('local');
        $layer = $this->layer();
        $this->source([1 => 'Үй', 2 => 'Здание']);
        $version = $this->snapshot($layer);
        $this->finish($version);
        $response = $this->get(route('gis.export', ['layer' => $layer->id, 'version' => $version->id]))->assertOk();
        $this->assertCount(2, json_decode($response->streamedContent(), true)['features']);
        $raster = GisLayer::factory()->create(['kind' => 'raster']);
        $old = $this->snapshot($raster);
        app(GisArchive::class)->put($old, 'tiles/0/0/0.png', 'historical-image', 'image/png');
        app(GisFeatures::class)->publish($old);
        $this->travel(1)->days();
        $new = $this->snapshot($raster);
        app(GisArchive::class)->put($new, 'tiles/0/0/0.png', 'current-image', 'image/png');
        app(GisFeatures::class)->publish($new);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->get(route('gis.tiles', ['layer' => $raster->id, 'version' => $old->id, 'z' => 0, 'x' => 0, 'y' => 0, 'format' => 'png']))->assertOk()->assertContent('historical-image');
        Http::assertNothingSent();
        $this->expectExceptionMessage('неизменяем');
        app(GisArchive::class)->put($old->refresh(), 'tiles/0/0/0.png', 'replacement', 'image/png');
    }
}
