<?php

namespace App\Models;

use App\Enums\RunStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property RunStatus $status
 * @property array{max_steps: int, max_tokens: int, max_tool_calls: int, timeout: int} $limits
 * @property array<string, int>|null $usage
 * @property array<string, mixed>|null $context
 * @property array<string, mixed>|null $output_data
 */
class AgentRun extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $attributes = ['kind' => 'workspace'];

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'limits' => 'array',
            'usage' => 'array',
            'context' => 'array',
            'output_data' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<SimulationScenario, $this> */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(SimulationScenario::class, 'simulation_scenario_id');
    }

    /** @return HasMany<RunEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(RunEvent::class);
    }

    /** @return HasMany<ToolApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(ToolApproval::class);
    }

    /** @param array<string, mixed> $data */
    public function record(string $type, array $data = []): void
    {
        $this->events()->create(['type' => $type, 'data' => $data]);
    }
}
