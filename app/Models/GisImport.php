<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property list<array<string, mixed>> $errors
 * @property array<string, mixed> $manifest
 */
class GisImport extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['errors' => 'array', 'manifest' => 'array'];
    }
}
