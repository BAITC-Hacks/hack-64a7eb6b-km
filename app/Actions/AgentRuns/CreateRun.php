<?php

namespace App\Actions\AgentRuns;

use App\Ai\Runtime\RuntimeConfiguration;
use App\Enums\RunStatus;
use App\Jobs\ExecuteAgentRun;
use App\Models\AgentRun;
use App\Models\SimulationScenario;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateRun
{
    /** @param array<string, mixed> $context */
    public function handle(User $user, string $input, string $requestKey, string $kind = 'workspace', ?SimulationScenario $scenario = null, array $context = []): AgentRun
    {
        Gate::forUser($user)->authorize('create', AgentRun::class);

        if ($scenario) {
            Gate::forUser($user)->authorize('view', $scenario);
        }
        $promptVersion = match ($kind) {
            'workspace' => config('agents.prompt_version'),
            'scenario_analysis' => 'scenario-analysis-v3',
            'scenario_chat' => 'scenario-chat-v1',
            default => throw new \DomainException('Unsupported run kind.'),
        };

        return DB::transaction(function () use ($user, $input, $requestKey, $kind, $scenario, $context, $promptVersion): AgentRun {
            // Serialize submissions per owner, including concurrent duplicate requests.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $existing = AgentRun::query()->where('user_id', $user->id)->where('request_key', $requestKey)->first();
            if ($existing) {
                return $existing;
            }

            if (AgentRun::query()->where('user_id', $user->id)->whereIn('status', [RunStatus::Queued, RunStatus::Running])->count() >= 3) {
                throw ValidationException::withMessages(['input' => 'Дождитесь завершения или отмените один из трёх активных запусков.']);
            }

            $runtime = app(RuntimeConfiguration::class)->resolve();
            $run = AgentRun::query()->create([
                'user_id' => $user->id,
                'request_key' => $requestKey,
                'status' => RunStatus::Queued,
                'input' => $input,
                'kind' => $kind,
                'simulation_scenario_id' => $scenario?->id,
                'context' => [...$context, 'runtime' => $runtime],
                'driver' => $runtime['driver'],
                'provider' => config('agents.provider'),
                'model' => config('agents.model'),
                'prompt_version' => $promptVersion,
                'limits' => [
                    'max_steps' => config('agents.max_steps'),
                    'max_tokens' => config('agents.max_tokens'),
                    'max_tool_calls' => config('agents.max_tool_calls'),
                    'timeout' => config('agents.timeout'),
                ],
            ]);
            $run->record('run.queued', [...$runtime, 'prompt_version' => $run->prompt_version]);
            ExecuteAgentRun::dispatch($run->id)->afterCommit();

            return $run;
        });
    }
}
