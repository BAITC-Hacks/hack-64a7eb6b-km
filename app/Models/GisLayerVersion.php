<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed> $metadata
 * @property array<string, mixed> $cursor
 * @property array{bbox3857: list<float>, max_zoom: int, padding_m?: int, padding_srid?: int, expected_tiles?: int}|null $coverage
 */
class GisLayerVersion extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array', 'cursor' => 'array', 'coverage' => 'array', 'observed_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<GisLayer, $this> */
    public function layer(): BelongsTo
    {
        return $this->belongsTo(GisLayer::class, 'gis_layer_id');
    }
}
