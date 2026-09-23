<?php

namespace App\Actions\Gis;

use App\Models\GisLayerVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GisVectorTile
{
    public function __construct(private GisArchive $archive, private GisTiles $tiles) {}

    public function render(GisLayerVersion $version, int $z, int $x, int $y): string
    {
        if (isset($version->coverage['bbox3857'])) {
            $range = $this->tiles->range($version->coverage['bbox3857'], $z);
            if ($x < $range[0] || $x > $range[2] || $y < $range[1] || $y > $range[3]) {
                return '';
            }
        }
        $cache = "gis/derived/{$version->id}/$z/$x/$y.pbf";
        $disk = Storage::disk(config('gis.disk'));
        if ($disk->exists($cache)) {
            return $disk->get($cache);
        }
        if ($this->archive->exists($version, 'tile-tree.json.gz')) {
            $node = $this->archive->readJson($version, 'tile-tree.json.gz')['index'] ?? 0;
            for ($depth = 0; $depth <= $z; $depth++) {
                $divisor = 2 ** ($z - $depth);
                if ($node === 2) {
                    $node = $this->archive->readJson($version, 'tile-tree/'.$depth.'/'.intdiv($x, $divisor).'/'.intdiv($y, $divisor).'.json.gz')['index'] ?? 0;
                }
                if ($node === 0) {
                    return '';
                }
                if ($node === 1 || $depth === $z) {
                    break;
                }
                $half = intdiv($divisor, 2);
                $node = $node[(intdiv($y, $half) % 2) * 2 + intdiv($x, $half) % 2] ?? 0;
            }
        }
        $body = '';
        for ($parentZ = min($z, (int) ($version->coverage['max_zoom'] ?? 18)); $parentZ >= 0; $parentZ--) {
            $scale = 2 ** ($z - $parentZ);
            $parentX = intdiv($x, $scale);
            $parentY = intdiv($y, $scale);
            $name = "tiles/$parentZ/$parentX/$parentY.pbf";
            if (! $this->archive->exists($version, $name)) {
                continue;
            }
            $body = $this->archive->read($version, $name);
            if ($body === '') {
                continue;
            }
            $body = $this->inflate($body);
            if ($parentZ < $z) {
                $body = $this->reslice($body, $parentZ, $parentX, $parentY, $z, $x, $y);
            }
            break;
        }
        $disk->put($cache, $body);

        return $body;
    }

    public function reslice(string $body, int $parentZ, int $parentX, int $parentY, int $z, int $x, int $y): string
    {
        $body = $this->inflate($body);
        $output = '';
        $bounds = $this->tiles->bounds($parentX, $parentY, $parentZ);
        foreach ($this->fields($body)[3] ?? [] as $layerBytes) {
            if (! is_string($layerBytes)) {
                throw new RuntimeException('Некорректный слой MVT.');
            }
            $layer = $this->fields($layerBytes);
            $name = $layer[1][0] ?? 'features';
            $extent = $layer[5][0] ?? 4096;
            $keys = $layer[3] ?? [];
            $values = array_map(fn (mixed $value): mixed => is_string($value) ? $this->value($value) : null, $layer[4] ?? []);
            $features = [];
            foreach ($layer[2] ?? [] as $featureBytes) {
                if (! is_string($featureBytes)) {
                    throw new RuntimeException('Некорректный объект MVT.');
                }
                $feature = $this->fields($featureBytes);
                $tags = $this->packed($feature[2][0] ?? '');
                $properties = [];
                for ($index = 0; $index + 1 < count($tags); $index += 2) {
                    $key = $keys[$tags[$index]] ?? null;
                    if (is_string($key)) {
                        $properties[$key] = $values[$tags[$index + 1]] ?? null;
                    }
                }
                $geometry = $this->geometry($this->packed($feature[4][0] ?? ''), (int) ($feature[3][0] ?? 0), (int) $extent, $bounds);
                if ($geometry !== null) {
                    $features[] = ['id' => $feature[1][0] ?? null, 'properties' => $properties === [] ? new \stdClass : $properties, 'geometry' => $geometry];
                }
            }
            if ($features === []) {
                continue;
            }
            $result = DB::selectOne("WITH bounds AS (SELECT ST_TileEnvelope(?,?,?) AS b), input AS (SELECT * FROM jsonb_to_recordset(?::jsonb) AS r(id bigint, properties jsonb, geometry jsonb)), features AS (SELECT id,properties,ST_AsMVTGeom(ST_SetSRID(ST_GeomFromGeoJSON(geometry),3857),bounds.b,4096,64,true) AS geom FROM input CROSS JOIN bounds) SELECT encode(ST_AsMVT(features,?,4096,'geom','id'),'base64') AS tile FROM features", [$z, $x, $y, json_encode($features, JSON_THROW_ON_ERROR), $name]);
            $output .= base64_decode($result->tile ?? '');
        }

        return $output;
    }

    public function inflate(string $body): string
    {
        if (str_starts_with($body, "\x1f\x8b")) {
            $decoded = gzdecode($body, 64 * 1024 * 1024);
            if ($decoded === false) {
                throw new RuntimeException('Повреждённое gzip-содержимое GIS.');
            }

            return $decoded;
        }

        return $body;
    }

    /** @return list<int> */
    public function glyphRanges(string $body): array
    {
        $ranges = [];
        foreach ($this->fields($this->inflate($body))[3] ?? [] as $layerBytes) {
            if (! is_string($layerBytes)) {
                continue;
            }
            foreach ($this->fields($layerBytes)[4] ?? [] as $valueBytes) {
                $value = is_string($valueBytes) ? $this->value($valueBytes) : null;
                if (! is_string($value)) {
                    continue;
                }
                foreach (mb_str_split($value) as $character) {
                    $codepoint = mb_ord($character);
                    $ranges[intdiv($codepoint, 256) * 256] = true;
                }
            }
        }

        return array_keys($ranges);
    }

    /** @return array<int, list<int|string>> */
    private function fields(string $body): array
    {
        $fields = [];
        $offset = 0;
        $length = strlen($body);
        while ($offset < $length) {
            $tag = $this->varint($body, $offset);
            $wire = $tag & 7;
            $field = $tag >> 3;
            if ($wire === 0) {
                $value = $this->varint($body, $offset);
            } else {
                $size = match ($wire) {
                    1 => 8, 2 => $this->varint($body, $offset), 5 => 4, default => throw new RuntimeException('Неподдерживаемое поле MVT.')
                };
                if ($size < 0 || $offset + $size > $length) {
                    throw new RuntimeException('Усечённый файл MVT.');
                }
                $value = substr($body, $offset, $size);
                $offset += $size;
            }
            $fields[$field][] = $value;
        }

        return $fields;
    }

    private function varint(string $body, int &$offset): int
    {
        $value = 0;
        for ($shift = 0; $shift < 70; $shift += 7) {
            if ($offset >= strlen($body)) {
                throw new RuntimeException('Усечённое число MVT.');
            }
            $byte = ord($body[$offset++]);
            $value |= ($byte & 127) << $shift;
            if ($byte < 128) {
                return $value;
            }
        }
        throw new RuntimeException('Некорректное число MVT.');
    }

    /** @return list<int> */
    private function packed(string|int $body): array
    {
        if (is_int($body)) {
            return [$body];
        }
        $values = [];
        $offset = 0;
        while ($offset < strlen($body)) {
            $values[] = $this->varint($body, $offset);
        }

        return $values;
    }

    private function value(string $body): mixed
    {
        $fields = $this->fields($body);
        if (isset($fields[1][0])) {
            return $fields[1][0];
        }
        if (isset($fields[2][0]) && is_string($fields[2][0])) {
            $value = unpack('gvalue', $fields[2][0]);
            if ($value === false) {
                throw new RuntimeException('Некорректное float-значение MVT.');
            }

            return $value['value'];
        }
        if (isset($fields[3][0]) && is_string($fields[3][0])) {
            $value = unpack('evalue', $fields[3][0]);
            if ($value === false) {
                throw new RuntimeException('Некорректное double-значение MVT.');
            }

            return $value['value'];
        }
        if (isset($fields[6][0]) && is_int($fields[6][0])) {
            return $this->signed($fields[6][0]);
        }
        if (isset($fields[7][0])) {
            return (bool) $fields[7][0];
        }

        return $fields[4][0] ?? $fields[5][0] ?? null;
    }

    private function signed(int $value): int
    {
        return ($value >> 1) ^ -($value & 1);
    }

    /** @param list<int> $commands
     * @param  list<float>  $bounds
     * @return array<string, mixed>|null
     */
    private function geometry(array $commands, int $type, int $extent, array $bounds): ?array
    {
        if ($extent <= 0) {
            throw new RuntimeException('Некорректный размер MVT.');
        }
        $x = 0;
        $y = 0;
        $paths = [];
        $path = [];
        $project = fn (int $px, int $py): array => [$bounds[0] + $px / $extent * ($bounds[2] - $bounds[0]), $bounds[3] - $py / $extent * ($bounds[3] - $bounds[1])];
        for ($offset = 0; $offset < count($commands);) {
            $command = $commands[$offset++];
            $id = $command & 7;
            $count = $command >> 3;
            for ($i = 0; $i < $count; $i++) {
                if ($id === 7 && $path !== []) {
                    $path[] = $path[0];
                } elseif (($id === 1 || $id === 2) && isset($commands[$offset], $commands[$offset + 1])) {
                    $x += $this->signed($commands[$offset++]);
                    $y += $this->signed($commands[$offset++]);
                    if ($id === 1 && $path !== []) {
                        $paths[] = $path;
                        $path = [];
                    }
                    $path[] = $project($x, $y);
                } else {
                    throw new RuntimeException('Некорректная геометрия MVT.');
                }
            }
        }
        if ($path !== []) {
            $paths[] = $path;
        }
        if ($paths === []) {
            return null;
        }

        return match ($type) {
            1 => ['type' => 'MultiPoint', 'coordinates' => array_merge(...$paths)],
            2 => ['type' => 'MultiLineString', 'coordinates' => $paths],
            3 => app(GisFeatures::class)->fromEsri(['rings' => $paths]),
            default => null,
        };
    }
}
