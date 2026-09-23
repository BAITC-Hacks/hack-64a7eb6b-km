<?php

namespace App\Models;

use Database\Factories\GisLayerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed> $metadata
 * @property list<array<string, mixed>> $occurrences
 */
class GisLayer extends Model
{
    /** @use HasFactory<GisLayerFactory> */
    use HasFactory;

    use HasUlids;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurrences' => 'array', 'checked_at' => 'immutable_datetime', 'active_version_id' => 'integer'];
    }

    /** @return HasMany<GisLayerVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(GisLayerVersion::class);
    }
}
