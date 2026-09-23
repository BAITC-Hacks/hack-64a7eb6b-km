<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed> $properties
 * @property array<string, mixed>|null $raw
 */
class GisFeatureContent extends Model
{
    protected $guarded = ['id'];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['properties' => 'array', 'raw' => 'array'];
    }
}
