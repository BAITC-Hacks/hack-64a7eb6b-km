<?php

namespace Tests\Feature;

use App\Actions\Gis\GisArchive;
use App\Actions\Gis\GisFeatures;
use App\Actions\Gis\GisTiles;
use App\Actions\Gis\GisVectorTile;
use App\Models\GisLayer;
use App\Models\GisLayerVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GisVectorTileTest extends TestCase
{
    use RefreshDatabase;

    public function test_sparse_gzip_parent_tile_is_clipped_to_child_with_names_properties_and_id_preserved(): void
    {
        Storage::fake('local');
        $layer = GisLayer::factory()->create(['kind' => 'vector_tile']);
        $version = GisLayerVersion::query()->create(['gis_layer_id' => $layer->id, 'observed_at' => now(), 'metadata' => [], 'cursor' => [], 'coverage' => ['max_zoom' => 18, 'bbox3857' => app(GisTiles::class)->bounds(715, 342, 10)]]);
        $parent = $this->pointTile(10, 715, 342);
        app(GisArchive::class)->put($version, 'tiles/10/715/342.pbf', gzencode($parent), 'application/x-protobuf');
        app(GisArchive::class)->json($version, 'style-original.json.gz', ['version' => 8, 'sources' => ['roads' => ['type' => 'vector', 'url' => 'https://gis.esaulet.kz/source']], 'glyphs' => '../fonts/{fontstack}/{range}.pbf', 'sprite' => '../sprites/sprite', 'layers' => [['id' => 'roads', 'source' => 'roads', 'source-layer' => 'roads', 'type' => 'circle', 'layout' => [], 'paint' => []]]]);
        app(GisFeatures::class)->publish($version);
        $expected = $this->pointTile(11, 1430, 684);
        $this->assertSame($expected, app(GisVectorTile::class)->render($version, 11, 1430, 684));
        $this->get(route('gis.tiles', ['layer' => $layer->id, 'version' => $version->id, 'z' => 11, 'x' => 1430, 'y' => 684, 'format' => 'pbf']))->assertOk()->assertContent($expected);
        $this->assertSame('', app(GisVectorTile::class)->render($version, 11, 0, 0));
        $this->assertContains(1024, app(GisVectorTile::class)->glyphRanges(gzencode($parent)));
        $response = $this->get(route('gis.style', ['layer' => $layer->id, 'version' => $version->id]))->assertOk();
        $this->assertInstanceOf(\stdClass::class, json_decode($response->getContent())->layers[0]->layout);
        $style = $response->json();
        $this->assertStringContainsString('/tiles/{z}/{x}/{y}.pbf', $style['sources']['roads']['tiles'][0]);
        $this->assertStringContainsString('/fonts/{fontstack}/{range}.pbf', $style['glyphs']);
        $this->assertStringNotContainsString('esaulet.kz', json_encode($style));
        Http::assertNothingSent();
    }

    private function pointTile(int $z, int $x, int $y): string
    {
        $result = DB::selectOne("SELECT encode(ST_AsMVT(f,'roads',4096,'geom','id'),'base64') AS tile FROM (SELECT 42::bigint AS id, '{\"label\":\"Қала\",\"height\":12.5,\"active\":true}'::jsonb AS properties, ST_AsMVTGeom(ST_Centroid(ST_TileEnvelope(11,1430,684)),ST_TileEnvelope(?,?,?),4096,64,true) AS geom) f", [$z, $x, $y]);

        return base64_decode($result->tile);
    }
}
