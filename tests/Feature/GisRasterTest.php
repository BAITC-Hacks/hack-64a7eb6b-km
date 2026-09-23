<?php

namespace Tests\Feature;

use App\Actions\Gis\GisArchive;
use App\Actions\Gis\GisSeedPackage;
use App\Actions\Gis\ImportGisLayer;
use App\Models\GisLayer;
use App\Models\GisLayerVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GisRasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_raster_block_resumes_from_archive_and_publishes_verified_local_pyramid(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $layer = GisLayer::factory()->create(['kind' => 'raster']);
        $version = GisLayerVersion::query()->create(['gis_layer_id' => $layer->id, 'observed_at' => now(), 'metadata' => [], 'cursor' => [], 'coverage' => ['bbox3857' => [1, 1, 2, 2], 'padding_m' => 1000, 'max_zoom' => 1]]);
        $image = imagecreatetruecolor(4096, 4096);
        imagefill($image, 0, 0, imagecolorallocate($image, 15, 80, 140));
        ob_start();
        imagepng($image);
        $body = ob_get_clean();
        app(GisArchive::class)->put($version, 'blocks/1/0/0.png', $body, 'image/png');
        $importer = app(ImportGisLayer::class);
        $this->assertFalse($importer->step($version));
        $this->assertSame('building', $version->fresh()->status);
        $this->assertTrue($importer->step($version->refresh()));
        $this->assertSame('published', $version->fresh()->status);
        $this->assertSame(2, $version->fresh()->coverage['expected_tiles']);
        $tile = $this->get(route('gis.tiles', ['layer' => $layer->id, 'version' => $version->id, 'z' => 0, 'x' => 0, 'y' => 0, 'format' => 'png']))->assertOk();
        $parent = imagecreatefromstring($tile->getContent());
        $this->assertSame(256, imagesx($parent));
        $this->assertSame(0x0F508C, imagecolorat($parent, 190, 60));
        Http::assertNothingSent();
    }

    public function test_missing_child_tile_cannot_be_silently_published_as_transparent(): void
    {
        Storage::fake('local');
        $layer = GisLayer::factory()->create(['kind' => 'raster']);
        $version = GisLayerVersion::query()->create(['gis_layer_id' => $layer->id, 'observed_at' => now(), 'metadata' => [], 'cursor' => ['z' => 0], 'coverage' => ['bbox3857' => [1, 1, 2, 2], 'padding_m' => 1000, 'max_zoom' => 1]]);
        try {
            app(ImportGisLayer::class)->step($version);
            $this->fail('An incomplete raster must not be published.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('дочерний растровый тайл', $error->getMessage());
        }
        $this->assertNull($version->fresh()->published_at);
        $this->assertNull($layer->fresh()->active_version_id);
    }

    public function test_partial_raster_seed_builds_only_available_parents_without_source_requests(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $layer = GisLayer::factory()->create(['kind' => 'raster']);
        $version = GisLayerVersion::query()->create(['gis_layer_id' => $layer->id, 'observed_at' => now(), 'coverage' => ['bbox3857' => [-20000000, -20000000, 20000000, 20000000], 'max_zoom' => 1, 'expected_tiles' => 5]]);
        $image = imagecreatetruecolor(256, 256);
        imagefill($image, 0, 0, imagecolorallocate($image, 15, 80, 140));
        ob_start();
        imagepng($image);
        app(GisArchive::class)->put($version, 'tiles/1/1/0.png', ob_get_clean(), 'image/png');
        app(GisSeedPackage::class)->freezeIncomplete();
        $this->assertSame('seeded', $version->fresh()->status);
        $this->assertFalse($version->fresh()->coverage['complete']);
        $this->assertSame(2, DB::table('gis_version_assets')->where('version_id', $version->id)->count());
        $response = $this->get(route('gis.tiles', ['layer' => $layer->id, 'version' => $version->id, 'z' => 0, 'x' => 0, 'y' => 0, 'format' => 'png']))->assertOk();
        $parent = imagecreatefromstring($response->getContent());
        $this->assertSame(0x0F508C, imagecolorat($parent, 190, 60));
        $this->assertSame(127, (imagecolorat($parent, 60, 60) >> 24) & 0x7F);
        Http::assertNothingSent();
    }

    public function test_bulk_tile_storage_deduplicates_content_without_losing_tile_addresses(): void
    {
        Storage::fake('local');
        $layer = GisLayer::factory()->create(['kind' => 'raster']);
        $version = GisLayerVersion::query()->create(['gis_layer_id' => $layer->id, 'observed_at' => now()]);
        app(GisArchive::class)->putMany($version, ['tiles/1/0/0.png' => 'same', 'tiles/1/0/1.png' => 'same', 'tiles/1/1/0.png' => 'different'], 'image/png');
        $this->assertSame(3, DB::table('gis_version_assets')->count());
        $this->assertSame(2, DB::table('gis_assets')->count());
        $this->assertSame('same', app(GisArchive::class)->read($version, 'tiles/1/0/1.png'));
    }
}
