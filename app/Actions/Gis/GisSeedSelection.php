<?php

namespace App\Actions\Gis;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class GisSeedSelection
{
    public const OMITTED = 'Не включён в компактный установочный seed. Данные доступны через отдельный импорт.';

    public function versions(): Builder
    {
        $sources = array_map(fn (string $source): string => 'https://'.config('gis.allowed_host').'/server/rest/services/'.$source, config('gis.seed_sources'));

        return DB::table('gis_layer_versions')->whereIn('id', DB::table('gis_layers')->whereNull('user_id')->whereIn('source_url', $sources)->select('active_version_id'))->whereIn('status', ['published', 'seeded'])->select('id');
    }

    public function assets(Builder $versions): Builder
    {
        return DB::table('gis_version_assets')->whereIn('version_id', $versions)
            ->where('name', 'not like', 'raw/%')
            ->whereRaw("CASE WHEN name LIKE 'tiles/%' THEN split_part(name, '/', 2)::integer <= ? ELSE true END", [config('gis.seed_tile_zoom')]);
    }

    /** @param iterable<\stdClass> $rows
     * @param  list<int>  $versionIds
     * @return \Generator<int, \stdClass>
     */
    public function rows(string $table, iterable $rows, array $versionIds): \Generator
    {
        foreach ($rows as $row) {
            if ($table === 'gis_layers' && ! in_array($row->active_version_id, $versionIds, true)) {
                $row->active_version_id = null;
                if ($row->status !== 'unavailable') {
                    $row->status = 'not_seeded';
                    $row->error = self::OMITTED;
                }
            }
            if ($table === 'gis_layer_versions') {
                $metadata = json_decode($row->metadata, true, flags: JSON_THROW_ON_ERROR);
                $metadata['package'] = ['profile' => 'compact', 'history' => 'active_snapshot_only', 'feature_content' => 'unchanged', 'raw_pages' => 'omitted_duplicate_of_feature_raw', 'tile_max_zoom' => config('gis.seed_tile_zoom')];
                $row->metadata = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $row->cursor = '{}';
                $coverage = json_decode($row->coverage ?? '{}', true, flags: JSON_THROW_ON_ERROR) ?? [];
                if (isset($coverage['max_zoom']) && $coverage['max_zoom'] > config('gis.seed_tile_zoom')) {
                    $coverage['source_max_zoom'] = $coverage['max_zoom'];
                    $coverage['max_zoom'] = config('gis.seed_tile_zoom');
                    $coverage['complete'] = false;
                    $row->status = 'seeded';
                    $row->error = 'Компактная подложка: сохранены доступные тайлы до z'.config('gis.seed_tile_zoom').'.';
                }
                $row->coverage = json_encode($coverage, JSON_THROW_ON_ERROR);
            }
            yield $row;
        }
    }
}
