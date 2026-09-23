<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GisAsset extends Model
{
    protected $guarded = ['id'];

    public $timestamps = false;

    protected $primaryKey = 'hash';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [];
    }
}
