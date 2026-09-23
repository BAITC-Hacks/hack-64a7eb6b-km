<?php

namespace App\Models;

use Database\Factories\SimulationScenarioFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property list<array{measure_id: string, district_id: ?string}> $selections
 * @property array<string, mixed> $result
 * @property list<array<string, mixed>> $alternatives
 */
class SimulationScenario extends Model
{
    /** @use HasFactory<SimulationScenarioFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['selections' => 'array', 'result' => 'array', 'alternatives' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Saved scenarios are immutable. Create a new variant.');
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<SimulationDataset, $this> */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(SimulationDataset::class, 'simulation_dataset_id');
    }

    /** @return HasMany<AgentRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }
}
