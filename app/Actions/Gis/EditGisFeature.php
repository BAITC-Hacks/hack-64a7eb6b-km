<?php

namespace App\Actions\Gis;

use App\Models\GisLayer;
use App\Models\GisLayerVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EditGisFeature
{
    public function __construct(private GisFeatures $features) {}

    /** @param array<string, mixed>|null $geometry
     * @param  array<string, mixed>  $properties
     */
    public function handle(User $user, GisLayer $layer, int $expectedVersion, ?string $sourceId, ?array $geometry, array $properties = [], bool $delete = false, ?int $restore = null): GisLayerVersion
    {
        Gate::forUser($user)->authorize('update', $layer);
        if (! $delete && $restore === null) {
            $this->validateGeometry($geometry, $properties);
        }

        return DB::transaction(function () use ($user, $layer, $expectedVersion, $sourceId, $geometry, $properties, $delete, $restore): GisLayerVersion {
            $layer = GisLayer::query()->lockForUpdate()->findOrFail($layer->id);
            abort_if($layer->active_version_id !== $expectedVersion, 409, 'Карта изменена в другой вкладке. Обновите слой перед сохранением.');
            if ($sourceId !== null && $restore === null) {
                abort_unless(DB::table('gis_version_features')->where('version_id', $expectedVersion)->where('source_id', $sourceId)->exists(), 404);
            }
            $version = GisLayerVersion::query()->create(['gis_layer_id' => $layer->id, 'author_id' => $user->id, 'metadata' => $layer->metadata, 'observed_at' => now(), 'cursor' => ['action' => $delete ? 'delete' : ($restore ? 'restore' : ($sourceId ? 'update' : 'create'))]]);
            DB::statement('INSERT INTO gis_version_features (version_id, feature_id, source_id) SELECT ?, feature_id, source_id FROM gis_version_features WHERE version_id = ?', [$version->id, $expectedVersion]);
            $id = $sourceId ?? (string) Str::uuid();
            if ($delete) {
                DB::table('gis_version_features')->where('version_id', $version->id)->where('source_id', $id)->delete();
            } elseif ($restore !== null) {
                $content = DB::table('gis_feature_contents')->where('id', $restore)->where('gis_layer_id', $layer->id)->where('source_id', $id)->first();
                abort_unless($content !== null, 404);
                DB::table('gis_version_features')->updateOrInsert(['version_id' => $version->id, 'source_id' => $id], ['feature_id' => $restore]);
            } else {
                $this->features->put($version, $id, $properties, $geometry);
            }
            $this->features->publish($version);

            return $version;
        });
    }

    /** @param array<string, mixed>|null $geometry
     * @param  array<string, mixed>  $properties
     */
    public function validateGeometry(?array $geometry, array $properties): void
    {
        if (! $geometry || strlen(json_encode($geometry, JSON_THROW_ON_ERROR)) > 500000 || strlen(json_encode($properties, JSON_THROW_ON_ERROR)) > 30000) {
            throw ValidationException::withMessages(['geometry' => 'Геометрия или атрибуты превышают допустимый размер.']);
        }
        $walk = function (mixed $coordinates) use (&$walk): bool {
            if (! is_array($coordinates) || $coordinates === []) {
                return false;
            }
            if (is_numeric($coordinates[0] ?? null)) {
                return count($coordinates) >= 2 && is_numeric($coordinates[1]) && is_finite((float) $coordinates[0]) && is_finite((float) $coordinates[1]) && abs((float) $coordinates[0]) <= 180 && abs((float) $coordinates[1]) <= 85.051129;
            }
            foreach ($coordinates as $child) {
                if (! $walk($child)) {
                    return false;
                }
            }

            return true;
        };
        if (! $walk($geometry['coordinates'] ?? null)) {
            throw ValidationException::withMessages(['geometry' => 'Некорректные координаты WGS84.']);
        }
        try {
            $result = DB::transaction(fn () => DB::selectOne('SELECT ST_IsValid(g) AND NOT ST_IsEmpty(g) AS valid FROM (SELECT ST_GeomFromGeoJSON(?) AS g) s', [json_encode($geometry, JSON_THROW_ON_ERROR)]));
            $valid = $result?->valid;
        } catch (QueryException) {
            $valid = false;
        }
        if (! $valid) {
            throw ValidationException::withMessages(['geometry' => 'Некорректная или самопересекающаяся геометрия.']);
        }
    }
}
