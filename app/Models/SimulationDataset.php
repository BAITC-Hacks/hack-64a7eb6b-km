<?php

namespace App\Models;

use Database\Factories\SimulationDatasetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** @property array<string, mixed> $data */
class SimulationDataset extends Model
{
    /** @use HasFactory<SimulationDatasetFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Dataset versions are immutable.');
        });
    }

    public static function current(): self
    {
        return static::query()->where('version', 'astana-v1')->firstOrFail();
    }
}
