<?php

namespace Tests\Feature;

use App\Actions\Gis\GisArchive;
use App\Actions\Gis\GisFeatures;
use App\Actions\Gis\GisSeedPackage;
use App\Actions\Gis\GisSeedSelection;
use App\Models\GisLayer;
use App\Models\GisLayerVersion;
use App\Models\User;
use Database\Seeders\GisDatasetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GisSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_bundled_dataset_installs_offline_with_all_features_files_and_serves_tiles(): void
    {
        if (! config('gis.verify_seed')) {
            $this->markTestSkipped('Opt-in dataset check: GIS_VERIFY_SEED=true php artisan test --filter=test_bundled_dataset');
        }
        Storage::fake('local');
        Http::preventStrayRequests();
        $path = config('gis.seed_path');
        $manifest = json_decode(file_get_contents($path.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $actualBytes = array_sum(array_map(fn ($file): int => $file->getSize(), File::allFiles($path)));
        $this->assertLessThanOrEqual(50_000_000, $actualBytes);
        $this->assertSame($manifest['package_bytes'], $actualBytes);
        $this->assertSame('compact', $manifest['profile']);
        $this->assertTrue(app(GisSeedPackage::class)->restore($path));
        foreach ($manifest['tables'] as $table => $parts) {
            $this->assertSame(array_sum(array_column($parts, 'rows')), DB::table($table)->count(), $table);
        }
        $this->assertSame(count($manifest['assets']), count(Storage::disk('local')->allFiles('gis/blobs')));
        $district = GisLayer::query()->where('source_url', config('gis.district_source'))->firstOrFail();
        $this->getJson(route('gis.features.index', ['layer' => $district->id, 'version' => $district->active_version_id]))->assertOk()->assertJsonCount(6, 'features');
        $buildings = GisLayer::query()->where('source_url', 'like', '%dop_sloi_geoportal_otkr/MapServer/18')->firstOrFail();
        $this->assertGreaterThanOrEqual(57000, $buildings->versions()->firstOrFail()->feature_count);
        $tile = DB::selectOne('SELECT floor((ST_X(p)+180)/360*16384)::integer AS x, floor((1-ln(tan(radians(ST_Y(p)))+1/cos(radians(ST_Y(p))))/pi())/2*16384)::integer AS y FROM (SELECT ST_PointOnSurface(f.geometry) AS p FROM gis_feature_contents f JOIN gis_version_features vf ON vf.feature_id=f.id WHERE vf.version_id=? AND f.geometry IS NOT NULL LIMIT 1) s', [$buildings->active_version_id]);
        $start = microtime(true);
        $response = $this->get(route('gis.tiles', ['layer' => $buildings->id, 'version' => $buildings->active_version_id, 'z' => 14, 'x' => $tile->x, 'y' => $tile->y, 'format' => 'pbf']))->assertOk();
        $this->assertNotEmpty($response->getContent());
        fwrite(STDOUT, sprintf("\nOffline seed: %d features; building tile %.3fs, %d bytes.\n", DB::table('gis_feature_contents')->count(), microtime(true) - $start, strlen($response->getContent())));
        $this->assertSame(0, GisLayer::query()->where('kind', 'raster')->whereNotNull('active_version_id')->count());
        $this->assertGreaterThan(0, GisLayer::query()->where('status', 'not_seeded')->count());
        $base = GisLayer::query()->where('source_url', 'like', '%basemap_ast_03052023/VectorTileServer')->firstOrFail();
        $this->getJson(route('gis.style', ['layer' => $base->id, 'version' => $base->active_version_id]))->assertOk()->assertJsonStructure(['sources', 'layers', 'glyphs']);
        $baseTile = DB::table('gis_version_assets as va')->join('gis_assets as a', 'a.hash', '=', 'va.asset_hash')->where('va.version_id', $base->active_version_id)->where('va.name', 'like', 'tiles/13/%')->where('a.bytes', '>', 0)->value('va.name');
        $coordinates = explode('/', $baseTile);
        $this->assertNotEmpty($this->get(route('gis.tiles', ['layer' => $base->id, 'version' => $base->active_version_id, 'z' => 13, 'x' => $coordinates[2], 'y' => (int) $coordinates[3], 'format' => 'pbf']))->assertOk()->getContent());
        Http::assertNothingSent();
    }

    public function test_compact_seed_keeps_selected_snapshot_and_original_features_marks_omissions_and_limits_tiles(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $features = app(GisFeatures::class);
        $archive = app(GisArchive::class);
        $layer = GisLayer::factory()->create(['source_url' => config('gis.district_source')]);
        $old = GisLayerVersion::query()->create(['gis_layer_id' => $layer->id, 'observed_at' => now()->subDay()]);
        $features->put($old, 'old', ['name' => 'Previous'], null);
        $features->publish($old);
        $current = GisLayerVersion::query()->create(['gis_layer_id' => $layer->id, 'observed_at' => now()]);
        $raw = ['geometry' => ['x' => 71.4, 'y' => 51.1, 'm' => 12], 'attributes' => ['name' => 'Астана · Қала']];
        $features->put($current, 'current', $raw['attributes'], ['type' => 'Point', 'coordinates' => [71.4, 51.1]], $raw);
        $archive->put($current, 'raw/page.json.gz', 'redundant-page', 'application/gzip');
        $archive->put($current, 'attachments/current/photo.jpg', 'photo', 'image/jpeg', 'current');
        $features->publish($current);
        $base = GisLayer::factory()->create(['kind' => 'vector_tile', 'source_url' => 'https://gis.esaulet.kz/server/rest/services/Hosted/basemap_ast_03052023/VectorTileServer']);
        $baseVersion = GisLayerVersion::query()->create(['gis_layer_id' => $base->id, 'observed_at' => now(), 'coverage' => ['max_zoom' => 18]]);
        $archive->put($baseVersion, 'tiles/13/1/1.pbf', 'coarse', 'application/x-protobuf');
        $large = $archive->put($baseVersion, 'tiles/18/1/1.pbf', 'detailed', 'application/x-protobuf');
        $archive->put($baseVersion, 'fonts/Tahoma/0-255.pbf', 'font', 'application/x-protobuf');
        $features->publish($baseVersion);
        $excluded = GisLayer::factory()->create(['kind' => 'raster']);
        $excludedVersion = GisLayerVersion::query()->create(['gis_layer_id' => $excluded->id, 'observed_at' => now()]);
        $features->publish($excludedVersion);
        $path = Storage::disk('local')->path('compact');
        $manifest = app(GisSeedPackage::class)->export($path, compact: true);
        $this->assertArrayNotHasKey($large, $manifest['assets']);
        $this->assertSame(4, GisLayerVersion::query()->count(), 'Export must not change the source archive.');
        $this->assertSame('published', $baseVersion->fresh()->status);
        $this->assertSame(18, $baseVersion->fresh()->coverage['max_zoom']);
        foreach ([$layer, $base, $excluded] as $source) {
            $source->delete();
        }
        $this->assertTrue(app(GisSeedPackage::class)->restore($path));
        $restored = GisLayer::query()->findOrFail($layer->id);
        $this->assertSame(1, $restored->versions()->count());
        $this->assertDatabaseMissing('gis_feature_contents', ['source_id' => 'old']);
        $this->getJson(route('gis.features.show', ['layer' => $layer->id, 'feature' => 'current', 'version' => $restored->active_version_id]))->assertOk()->assertJsonPath('raw', fn (array $value): bool => $features->canonical($value) === $features->canonical($raw))->assertJsonPath('feature.geometry.coordinates', [71.4, 51.1]);
        $snapshot = $restored->versions()->firstOrFail();
        $this->assertFalse($archive->exists($snapshot, 'raw/page.json.gz'));
        $this->assertSame('photo', $archive->read($snapshot, 'attachments/current/photo.jpg'));
        $baseSnapshot = GisLayer::query()->findOrFail($base->id)->versions()->firstOrFail();
        $this->assertSame('seeded', $baseSnapshot->status);
        $this->assertSame(13, $baseSnapshot->coverage['max_zoom']);
        $this->assertSame('coarse', $archive->read($baseSnapshot, 'tiles/13/1/1.pbf'));
        $this->assertSame('font', $archive->read($baseSnapshot, 'fonts/Tahoma/0-255.pbf'));
        $this->assertFalse($archive->exists($baseSnapshot, 'tiles/18/1/1.pbf'));
        $this->assertNull(GisLayer::query()->findOrFail($excluded->id)->active_version_id);
        $catalog = collect($this->getJson(route('gis.layers.index'))->assertOk()->json('layers'))->keyBy('id');
        $this->assertSame('not_seeded', $catalog[$excluded->id]['status']);
        $this->assertSame(GisSeedSelection::OMITTED, $catalog[$excluded->id]['error']);
        Http::assertNothingSent();
    }

    public function test_oversized_compact_seed_is_not_published_and_source_archive_is_preserved(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['gis.seed_max_bytes' => 1]);
        $layer = GisLayer::factory()->create(['source_url' => config('gis.district_source')]);
        $version = GisLayerVersion::query()->create(['gis_layer_id' => $layer->id, 'observed_at' => now()]);
        app(GisFeatures::class)->publish($version);
        $path = Storage::disk('local')->path('oversized');
        try {
            app(GisSeedPackage::class)->export($path, compact: true);
            $this->fail('Oversized package must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('превышает лимит', $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($path);
        $this->assertSame([], glob($path.'.building-*'));
        $this->assertSame($version->id, $layer->fresh()->active_version_id);
        Http::assertNothingSent();
    }

    public function test_seed_restores_all_public_data_offline_preserves_history_and_excludes_private_data(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->freezeTime();
        $archive = app(GisArchive::class);
        $features = app(GisFeatures::class);
        $package = app(GisSeedPackage::class);
        $layer = GisLayer::factory()->create(['title' => 'Границы · Астана']);
        $version = GisLayerVersion::query()->create(['gis_layer_id' => $layer->id, 'observed_at' => now(), 'expected_count' => 3, 'metadata' => ['layer' => ['fields' => [['name' => 'name', 'alias' => 'Название']]]]]);
        $raw = ['attributes' => ['name' => 'Қазақша'], 'geometry' => ['x' => 71.4, 'y' => 51.1, 'm' => 19]];
        $features->put($version, '1', ['name' => 'Қазақша'], ['type' => 'Point', 'coordinates' => [71.4, 51.1]], $raw);
        $archive->put($version, 'attachments/1/photo.jpg', 'public-file', 'image/jpeg', '1');
        $owner = User::factory()->create();
        $private = GisLayer::factory()->create(['user_id' => $owner->id, 'kind' => 'custom']);
        $privateVersion = GisLayerVersion::query()->create(['gis_layer_id' => $private->id, 'observed_at' => now()]);
        $privateContent = $features->put($privateVersion, 'secret', ['name' => 'PRIVATE'], null);
        $secretHash = $archive->put($privateVersion, 'secret.txt', 'PRIVATE', 'text/plain');
        $features->publish($privateVersion);
        $package->freezeIncomplete();
        $path = Storage::disk('local')->path('package');
        $manifest = $package->export($path);
        $this->assertCount(1, $manifest['layers']);
        $this->assertFalse($manifest['layers'][0]['complete']);
        $this->assertSame(1, $manifest['layers'][0]['features']);
        $this->assertArrayNotHasKey($secretHash, $manifest['assets']);
        $this->assertFileDoesNotExist($path.'/blobs/'.$secretHash);
        $layer->delete();
        Storage::disk('local')->deleteDirectory('gis');
        config(['gis.seed_enabled' => true, 'gis.seed_path' => $path]);
        $this->seed(GisDatasetSeeder::class);
        $restored = GisLayer::query()->findOrFail($layer->id);
        $restoredVersion = $restored->versions()->firstOrFail();
        $this->assertSame('seeded', $restoredVersion->status);
        $this->assertSame(3, $restoredVersion->expected_count);
        $this->assertGreaterThan($privateVersion->id, $restoredVersion->id);
        $this->assertSame($restoredVersion->id, $restored->active_version_id);
        $this->assertSame($privateVersion->id, $private->fresh()->active_version_id);
        $this->assertDatabaseHas('gis_feature_contents', ['id' => $privateContent, 'source_id' => 'secret']);
        $this->assertSame('public-file', $archive->read($restoredVersion, 'attachments/1/photo.jpg'));
        $this->getJson(route('gis.features.show', ['layer' => $layer->id, 'feature' => '1', 'version' => $restoredVersion->id]))
            ->assertOk()->assertJsonPath('feature.properties.name', 'Қазақша')->assertJsonPath('feature.geometry.coordinates', [71.4, 51.1])->assertJsonPath('raw.geometry.m', 19);
        $this->getJson(route('gis.layers.index'))->assertOk()->assertJsonPath('layers.0.complete', false)->assertJsonPath('layers.0.count', 1);
        $this->getJson(route('gis.features.history', ['layer' => $layer->id, 'feature' => '1']))->assertOk()->assertJsonPath('data.0.content_id', DB::table('gis_version_features')->where('version_id', $restoredVersion->id)->value('feature_id'));
        $this->assertFalse($package->restore($path));
        $this->assertSame(2, GisLayerVersion::query()->count());
        Http::assertNothingSent();
    }

    public function test_seed_preserves_invalid_source_geometry_without_silently_repairing_it(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $layer = GisLayer::factory()->create();
        $version = GisLayerVersion::query()->create(['gis_layer_id' => $layer->id, 'observed_at' => now()]);
        app(GisFeatures::class)->put($version, 'bad-ring', ['name' => 'Source geometry'], ['type' => 'Polygon', 'coordinates' => [[[71.4, 51.1], [71.5, 51.2], [71.4, 51.1]]]]);
        app(GisFeatures::class)->put($version, "tab\tline\n\\N", ['name' => "Slash \\N and new\nline", 'nullable' => null], null);
        app(GisFeatures::class)->publish($version);
        $before = DB::table('gis_feature_contents')->where('source_id', 'bad-ring')->selectRaw("encode(ST_AsEWKB(geometry),'hex') AS geometry")->first()->geometry;
        $package = app(GisSeedPackage::class);
        $path = Storage::disk('local')->path('package');
        $package->export($path);
        $layer->delete();
        $this->assertTrue($package->restore($path));
        $this->assertDatabaseHas('gis_feature_contents', ['source_id' => "tab\tline\n\\N", 'geometry' => null]);
        $this->assertSame("Slash \\N and new\nline", json_decode(DB::table('gis_feature_contents')->whereNull('geometry')->value('properties'), true)['name']);
        $after = DB::table('gis_feature_contents')->where('source_id', 'bad-ring')->selectRaw("encode(ST_AsEWKB(geometry),'hex') AS geometry, ST_IsValid(geometry) AS valid")->first();
        $this->assertSame($before, $after->geometry);
        $this->assertFalse($after->valid);
        Http::assertNothingSent();
    }

    public function test_corrupt_package_is_rejected_without_installing_a_partial_catalog(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $layer = GisLayer::factory()->create();
        $version = GisLayerVersion::query()->create(['gis_layer_id' => $layer->id, 'observed_at' => now()]);
        app(GisFeatures::class)->publish($version);
        $package = app(GisSeedPackage::class);
        $path = Storage::disk('local')->path('package');
        $manifest = $package->export($path);
        $layer->delete();
        file_put_contents($path.'/'.$manifest['tables']['gis_layer_versions'][0]['file'], 'corrupt');
        try {
            $package->restore($path);
            $this->fail('Corrupt seed must fail.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('целостности', $error->getMessage());
        }
        $this->assertSame(0, GisLayer::query()->count());
        Http::assertNothingSent();
    }

    public function test_partial_snapshot_never_replaces_complete_snapshot_or_reports_false_deletions(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $layer = GisLayer::factory()->create();
        $first = GisLayerVersion::query()->create(['gis_layer_id' => $layer->id, 'observed_at' => now()]);
        app(GisFeatures::class)->put($first, '1', ['name' => 'Complete'], null);
        app(GisFeatures::class)->publish($first);
        $partial = GisLayerVersion::query()->create(['gis_layer_id' => $layer->id, 'observed_at' => now(), 'expected_count' => 1]);
        app(GisSeedPackage::class)->freezeIncomplete();
        $this->assertSame($first->id, $layer->fresh()->active_version_id);
        $this->getJson(route('gis.layers.index'))->assertOk()->assertJsonPath('layers.0.version_id', $first->id)->assertJsonPath('layers.0.complete', true);
        $this->getJson(route('gis.compare', ['layer' => $layer->id, 'left' => $first->id, 'right' => $partial->id]))->assertUnprocessable();
        $this->getJson(route('gis.features.history', ['layer' => $layer->id, 'feature' => '1']))->assertOk()->assertJsonCount(1, 'data');
        Http::assertNothingSent();
        $this->expectException(\RuntimeException::class);
        app(GisArchive::class)->put($partial->refresh(), 'mutate', 'no', 'text/plain');
    }
}
