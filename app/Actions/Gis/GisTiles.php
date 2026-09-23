<?php

namespace App\Actions\Gis;

use App\Models\GisLayer;
use App\Models\GisLayerVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GisTiles
{
    private const WORLD = 20037508.342789244;

    public function __construct(private ArcGisClient $client, private GisArchive $archive) {}

    public function step(GisLayerVersion $version): bool
    {
        $layer = $version->layer;
        $cursor = $version->cursor;
        if (! $version->coverage || ! isset($version->coverage['padding_m'])) {
            $district = GisLayer::query()->where('source_url', config('gis.district_source'))->first();
            if (! $district?->active_version_id) {
                throw new RuntimeException('Ожидание архива границ районов.', 429);
            }
            $bounds = DB::selectOne('SELECT ST_XMin(b) AS west, ST_YMin(b) AS south, ST_XMax(b) AS east, ST_YMax(b) AS north FROM (SELECT ST_Envelope(ST_Transform(ST_Expand(ST_Envelope(ST_Collect(ST_Transform(f.geometry, 32642))), 1000), 3857)) AS b FROM gis_feature_contents f JOIN gis_version_features vf ON vf.feature_id=f.id WHERE vf.version_id=?) s', [$district->active_version_id]);
            if (! $bounds || $bounds->west === null) {
                throw new RuntimeException('Границы районов не содержат геометрии.');
            }
            $version->update(['coverage' => ['bbox3857' => [(float) $bounds->west, (float) $bounds->south, (float) $bounds->east, (float) $bounds->north], 'max_zoom' => (int) config('gis.max_zoom'), 'padding_m' => 1000, 'padding_srid' => 32642], 'cursor' => []]);
            $cursor = [];
        }
        $maxZoom = (int) $version->coverage['max_zoom'];
        if ($layer->kind === 'raster' && ! isset($version->coverage['expected_tiles'])) {
            $expected = 0;
            for ($level = 0; $level <= $maxZoom; $level++) {
                $range = $this->range($version->coverage['bbox3857'], $level);
                $expected += ($range[2] - $range[0] + 1) * ($range[3] - $range[1] + 1);
            }
            $version->update(['coverage' => $version->coverage + ['expected_tiles' => $expected]]);
        }
        if ($layer->kind === 'vector_tile' && empty($cursor['resources'])) {
            $this->resources($version);
            $cursor['resources'] = true;
            $cursor['z'] = 0;
            $version->update(['cursor' => $cursor]);

            return false;
        }
        if ($layer->kind === 'raster') {
            $z = (int) ($cursor['z'] ?? $maxZoom);
            $range = $this->range($version->coverage['bbox3857'], $z);
            $x = (int) ($cursor['x'] ?? ($z === $maxZoom ? intdiv($range[0], 16) * 16 : $range[0]));
            $y = (int) ($cursor['y'] ?? ($z === $maxZoom ? intdiv($range[1], 16) * 16 : $range[1]));
            if ($z === $maxZoom) {
                $this->rasterBlock($version, $x, $y, $z, $range);
                $x += 16;
                if ($x > $range[2]) {
                    $x = intdiv($range[0], 16) * 16;
                    $y += 16;
                }
            } else {
                $files = [];
                for ($i = 0; $i < 64 && $y <= $range[3]; $i++) {
                    $files["tiles/$z/$x/$y.png"] = $this->parentTile($version, $x, $y, $z);
                    $x++;
                    if ($x > $range[2]) {
                        $x = $range[0];
                        $y++;
                    }
                }
                $this->archive->putMany($version, $files, 'image/png');
            }
            if ($y > $range[3]) {
                if ($z === 0) {
                    $this->verifyRaster($version);

                    return true;
                }
                $version->update(['cursor' => ['z' => $z - 1]]);
            } else {
                $version->update(['cursor' => ['z' => $z, 'x' => $x, 'y' => $y]]);
            }

            return false;
        }
        if (! $this->archive->exists($version, 'tile-plan.json.gz')) {
            $tree = $this->client->json($layer->source_url.'/tilemap');
            $this->archive->json($version, 'tile-tree.json.gz', $tree);
            $plan = [];
            $this->visitTiles($tree['index'] ?? 0, 0, 0, 0, $version, $plan);
            $this->archive->json($version, 'tile-plan.json.gz', $plan);
            $version->update(['coverage' => $version->coverage + ['expected_tiles' => count($plan)], 'cursor' => ['resources' => true, 'tile_offset' => 0]]);

            return false;
        }
        $plan = array_values($this->archive->readJson($version, 'tile-plan.json.gz'));
        $offset = (int) ($cursor['tile_offset'] ?? 0);
        $started = microtime(true);
        for ($i = 0; $i < 32 && $offset < count($plan) && microtime(true) - $started < 70; $i++, $offset++) {
            [$z, $x, $y] = $plan[$offset];
            $name = "tiles/$z/$x/$y.pbf";
            if (! $this->archive->exists($version, $name)) {
                $body = '';
                if ($this->tilePresent($version, $x, $y, $z)) {
                    try {
                        $body = $this->client->get($layer->source_url."/tile/$z/$y/$x.pbf")->body();
                    } catch (RuntimeException $error) {
                        if ($error->getCode() !== 404) {
                            throw $error;
                        }
                    }
                }
                $this->archive->put($version, $name, $body, 'application/x-protobuf');
            }
        }
        $version->update(['cursor' => array_replace($cursor, ['resources' => true, 'tile_offset' => $offset, 'tile_total' => count($plan)])]);

        return $offset >= count($plan) && $this->completeFonts($version, $plan);
    }

    private function verifyRaster(GisLayerVersion $version): void
    {
        for ($z = 0; $z <= $version->coverage['max_zoom']; $z++) {
            $range = $this->range($version->coverage['bbox3857'], $z);
            $count = DB::table('gis_version_assets')->where('version_id', $version->id)->where('name', 'like', "tiles/$z/%.png")->whereRaw("split_part(name, '/', 3)::integer BETWEEN ? AND ?", [$range[0], $range[2]])->whereRaw("split_part(split_part(name, '/', 4), '.', 1)::integer BETWEEN ? AND ?", [$range[1], $range[3]])->count();
            if ($count !== ($range[2] - $range[0] + 1) * ($range[3] - $range[1] + 1)) {
                throw new RuntimeException('Растровая пирамида неполная на уровне '.$z.'. Снимок не опубликован.');
            }
        }
    }

    /** @param list<list<int>> $plan */
    private function completeFonts(GisLayerVersion $version, array $plan): bool
    {
        if (! $this->archive->exists($version, 'style-original.json.gz')) {
            return true;
        }
        $style = $this->archive->readJson($version, 'style-original.json.gz');
        if (empty($style['glyphs'])) {
            return true;
        }
        $cursor = $version->cursor;
        $offset = (int) ($cursor['glyph_scan'] ?? 0);
        $ranges = $cursor['glyph_ranges'] ?? [0, 256, 1024, 8192];
        foreach (array_slice($plan, $offset, 128) as [$z, $x, $y]) {
            $ranges = array_values(array_unique([...$ranges, ...app(GisVectorTile::class)->glyphRanges($this->archive->read($version, "tiles/$z/$x/$y.pbf"))]));
            $offset++;
        }
        $cursor['glyph_scan'] = $offset;
        $cursor['glyph_ranges'] = $ranges;
        $version->update(['cursor' => $cursor]);
        if ($offset < count($plan)) {
            return false;
        }
        $fonts = [];
        foreach ($style['layers'] ?? [] as $item) {
            $font = $item['layout']['text-font'] ?? [];
            if ($font && is_string($font[0] ?? null) && ! in_array($font[0], ['get', 'case', 'match', 'literal'])) {
                $fonts[implode(',', $font)] = true;
            }
        }
        $downloaded = 0;
        foreach (array_keys($fonts) as $font) {
            foreach ($ranges as $start) {
                $range = $start.'-'.($start + 255);
                $name = 'fonts/'.$font.'/'.$range.'.pbf';
                if ($this->archive->exists($version, $name)) {
                    continue;
                }
                $url = str_replace(['{fontstack}', '{range}'], [rawurlencode($font), $range], $style['glyphs']);
                $response = $this->client->get($this->client->url($version->layer->source_url.'/resources/styles/root.json', $url));
                $this->archive->put($version, $name, $response->body(), 'application/x-protobuf');
                if (++$downloaded >= 2) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<mixed>|int $node
     * @param  list<list<int>>  $plan
     */
    private function visitTiles(array|int $node, int $z, int $x, int $y, GisLayerVersion $version, array &$plan): void
    {
        $range = $this->range($version->coverage['bbox3857'], $z);
        if ($node === 0 || $x < $range[0] || $x > $range[2] || $y < $range[1] || $y > $range[3]) {
            return;
        }
        if ($node === 2) {
            $index = $this->client->json($version->layer->source_url."/tilemap/$z/$y/$x");
            $this->archive->json($version, "tile-tree/$z/$x/$y.json.gz", $index);
            $node = $index['index'] ?? 0;
            if ($node === 2) {
                throw new RuntimeException('Источник не раскрыл вложенный индекс тайлов.');
            }
        }
        $plan[] = [$z, $x, $y];
        if (is_array($node) && $z < $version->coverage['max_zoom']) {
            foreach ($node as $quadrant => $child) {
                $this->visitTiles($child, $z + 1, $x * 2 + $quadrant % 2, $y * 2 + intdiv($quadrant, 2), $version, $plan);
            }
        }
    }

    private function tilePresent(GisLayerVersion $version, int $x, int $y, int $z): bool
    {
        $row = intdiv($y, 128) * 128;
        $col = intdiv($x, 128) * 128;
        $name = "tilemap/$z/$row/$col.json.gz";
        if (! $this->archive->exists($version, $name)) {
            try {
                $width = min(128, 2 ** $z);
                $map = $this->client->json($version->layer->source_url."/tilemap/$z/$row/$col/$width/$width");
            } catch (RuntimeException $error) {
                if ($error->getCode() === 422) {
                    $this->archive->json($version, $name, ['location' => ['top' => $row, 'left' => $col, 'width' => min(128, 2 ** $z)], 'data' => array_fill(0, min(128, 2 ** $z) ** 2, 0)]);

                    return false;
                }
                if ($error->getCode() !== 404) {
                    throw $error;
                }

                return true;
            }
            $this->archive->json($version, $name, $map);
        }
        $map = $this->archive->readJson($version, $name);
        $location = $map['location'] ?? [];
        $top = (int) ($location['top'] ?? $row);
        $left = (int) ($location['left'] ?? $col);
        $width = (int) ($location['width'] ?? 128);
        if ($x < $left || $y < $top || $x >= $left + $width) {
            return true;
        }

        return ($map['data'][($y - $top) * $width + $x - $left] ?? 1) !== 0;
    }

    private function resources(GisLayerVersion $version): void
    {
        $root = $version->layer->source_url.'/resources/styles/root.json';
        $style = $this->client->json($root);
        $this->archive->json($version, 'style-original.json.gz', $style);
        $this->archive->json($version, 'metadata.json.gz', $version->metadata);
        if (isset($style['sprite'])) {
            foreach (['.json', '.png', '@2x.json', '@2x.png'] as $suffix) {
                $url = $this->client->url($root, $style['sprite'].$suffix);
                $response = $this->client->get($url);
                $this->archive->put($version, 'sprite'.$suffix, $response->body(), str_ends_with($suffix, 'png') ? 'image/png' : 'application/json');
            }
        }
        $fonts = [];
        foreach ($style['layers'] ?? [] as $item) {
            $font = $item['layout']['text-font'] ?? [];
            if ($font && is_string($font[0] ?? null) && ! in_array($font[0], ['get', 'case', 'match', 'literal'])) {
                $fonts[implode(',', $font)] = true;
            }
        }
        if (isset($style['glyphs'])) {
            foreach (array_keys($fonts) as $font) {
                foreach (['0-255', '256-511', '1024-1279'] as $range) {
                    $url = str_replace(['{fontstack}', '{range}'], [rawurlencode($font), $range], $style['glyphs']);
                    $response = $this->client->get($this->client->url($root, $url));
                    $this->archive->put($version, 'fonts/'.$font.'/'.$range.'.pbf', $response->body(), 'application/x-protobuf');
                }
            }
        }
    }

    /** @param list<int> $range */
    private function rasterBlock(GisLayerVersion $version, int $x, int $y, int $z, array $range): void
    {
        $left = $this->bounds($x, $y + 16, $z);
        $right = $this->bounds($x + 16, $y, $z);
        $bbox = [$left[0], $left[3], $right[0], $right[3]];
        $block = "blocks/$z/$x/$y.png";
        if (! $this->archive->exists($version, $block)) {
            $response = $this->client->get($version->metadata['service_url'].'/export', ['f' => 'image', 'bbox' => implode(',', $bbox), 'bboxSR' => 3857, 'imageSR' => 3857, 'size' => '4096,4096', 'format' => 'png32', 'transparent' => 'true', 'layers' => 'show:'.$version->metadata['layer']['id']]);
            $body = $response->body();
        } else {
            $body = $this->archive->read($version, $block);
        }
        $image = @imagecreatefromstring($body);
        if (! $image || imagesx($image) !== 4096 || imagesy($image) !== 4096) {
            throw new RuntimeException('GIS вернул некорректный растровый блок.');
        }
        $this->archive->put($version, $block, $body, 'image/png');
        $files = [];
        for ($dx = 0; $dx < 16; $dx++) {
            for ($dy = 0; $dy < 16; $dy++) {
                if ($x + $dx < $range[0] || $x + $dx > $range[2] || $y + $dy < $range[1] || $y + $dy > $range[3]) {
                    continue;
                }
                $tile = imagecrop($image, ['x' => $dx * 256, 'y' => $dy * 256, 'width' => 256, 'height' => 256]);
                if (! $tile) {
                    throw new RuntimeException('Ошибка разбиения растра.');
                }
                $files['tiles/'.$z.'/'.($x + $dx).'/'.($y + $dy).'.png'] = $this->png($tile);
            }
        }
        $this->archive->putMany($version, $files, 'image/png');
    }

    public function completeAvailableRaster(GisLayerVersion $version): void
    {
        for ($z = (int) ($version->coverage['max_zoom'] ?? 0) - 1; $z >= 0; $z--) {
            $children = DB::table('gis_version_assets as va')->join('gis_assets as a', 'a.hash', '=', 'va.asset_hash')->where('va.version_id', $version->id)->where('va.name', 'like', 'tiles/'.($z + 1).'/%.png')->pluck('a.path', 'va.name')->all();
            $parents = DB::table('gis_version_assets')->where('version_id', $version->id)
                ->where('name', 'like', 'tiles/'.($z + 1).'/%.png')
                ->selectRaw("DISTINCT split_part(name, '/', 3)::integer / 2 AS x, split_part(split_part(name, '/', 4), '.', 1)::integer / 2 AS y")->get();
            $files = [];
            foreach ($parents as $parent) {
                $files["tiles/$z/{$parent->x}/{$parent->y}.png"] = $this->parentTile($version, $parent->x, $parent->y, $z, true, $children);
                if (count($files) >= 64) {
                    $this->archive->putMany($version, $files, 'image/png');
                    $files = [];
                }
            }
            $this->archive->putMany($version, $files, 'image/png');
        }
    }

    /** @param array<string, string>|null $children */
    private function parentTile(GisLayerVersion $version, int $x, int $y, int $z, bool $allowMissing = false, ?array $children = null): string
    {
        $range = $this->range($version->coverage['bbox3857'], $z + 1);
        $image = imagecreatetruecolor(256, 256);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        if ($transparent === false) {
            throw new RuntimeException('Ошибка создания прозрачного тайла.');
        }
        imagefill($image, 0, 0, $transparent);
        for ($dx = 0; $dx < 2; $dx++) {
            for ($dy = 0; $dy < 2; $dy++) {
                $name = 'tiles/'.($z + 1).'/'.($x * 2 + $dx).'/'.($y * 2 + $dy).'.png';
                $body = $children !== null ? (isset($children[$name]) ? Storage::disk(config('gis.disk'))->get($children[$name]) : null) : ($this->archive->exists($version, $name) ? $this->archive->read($version, $name) : null);
                if ($body !== null) {
                    $child = imagecreatefromstring($body);
                    if ($child) {
                        imagecopyresampled($image, $child, $dx * 128, $dy * 128, 0, 0, 128, 128, 256, 256);
                    }
                } elseif (! $allowMissing && $x * 2 + $dx >= $range[0] && $x * 2 + $dx <= $range[2] && $y * 2 + $dy >= $range[1] && $y * 2 + $dy <= $range[3]) {
                    throw new RuntimeException('В архиве отсутствует дочерний растровый тайл.');
                }
            }
        }

        return $this->png($image);
    }

    private function png(\GdImage $image): string
    {
        imagesavealpha($image, true);
        ob_start();
        imagepng($image, null, 6);
        $body = ob_get_clean();
        if ($body === false) {
            throw new RuntimeException('Не удалось сформировать PNG-тайл.');
        }

        return $body;
    }

    /** @param list<float> $bbox
     * @return list<int>
     */
    public function range(array $bbox, int $z): array
    {
        $scale = 2 ** $z;

        return [max(0, (int) floor(($bbox[0] + self::WORLD) / (2 * self::WORLD) * $scale)), max(0, (int) floor((self::WORLD - $bbox[3]) / (2 * self::WORLD) * $scale)), min($scale - 1, (int) floor(($bbox[2] + self::WORLD) / (2 * self::WORLD) * $scale)), min($scale - 1, (int) floor((self::WORLD - $bbox[1]) / (2 * self::WORLD) * $scale))];
    }

    /** @return list<float> */
    public function bounds(int $x, int $y, int $z): array
    {
        $size = 2 * self::WORLD / (2 ** $z);

        return [-self::WORLD + $x * $size, self::WORLD - ($y + 1) * $size, -self::WORLD + ($x + 1) * $size, self::WORLD - $y * $size];
    }
}
