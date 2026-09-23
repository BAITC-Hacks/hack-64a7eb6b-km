<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed> $state
 */
class GisMapState extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['state' => 'array'];
    }
}
