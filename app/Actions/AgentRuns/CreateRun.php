<?php

namespace App\Actions\AgentRuns;

use App\Enums\RunStatus;
use App\Jobs\ExecuteAgentRun;
use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateRun
{
    public function handle(User $user, string $input, string $requestKey): AgentRun
    {
        Gate::forUser($user)->authorize('create', AgentRun::class);

        return DB::transaction(function () use ($user, $input, $requestKey): AgentRun {
            // Serialize submissions per owner, including concurrent duplicate requests.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $existing = AgentRun::query()->where('user_id', $user->id)->where('request_key', $requestKey)->first();
            if ($existing) {
                return $existing;
            }

            if (AgentRun::query()->where('user_id', $user->id)->whereIn('status', [RunStatus::Queued, RunStatus::Running])->count() >= 3) {
                throw ValidationException::withMessages(['input' => 'Дождитесь завершения или отмените один из трёх активных запусков.']);
            }

            $run = AgentRun::query()->create([
                'user_id' => $user->id,
                'request_key' => $requestKey,
                'status' => RunStatus::Queued,
                'input' => $input,
                'driver' => config('agents.driver'),
                'provider' => config('agents.provider'),
                'model' => config('agents.model'),
                'prompt_version' => config('agents.prompt_version'),
                'limits' => [
                    'max_steps' => config('agents.max_steps'),
                    'max_tokens' => config('agents.max_tokens'),
                    'max_tool_calls' => config('agents.max_tool_calls'),
                    'timeout' => config('agents.timeout'),
                ],
            ]);
            $run->record('run.queued', ['driver' => $run->driver, 'prompt_version' => $run->prompt_version]);
            ExecuteAgentRun::dispatch($run->id)->afterCommit();

            return $run;
        });
    }
}
