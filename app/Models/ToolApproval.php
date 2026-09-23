<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** @property array<string, mixed> $arguments */
class ToolApproval extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['arguments' => 'array', 'resolved_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    /** @return HasOne<SimulationScenario, $this> */
    public function scenario(): HasOne
    {
        return $this->hasOne(SimulationScenario::class, 'tool_approval_id');
    }
}
