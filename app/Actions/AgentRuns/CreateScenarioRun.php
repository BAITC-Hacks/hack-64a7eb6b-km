<?php

namespace App\Actions\AgentRuns;

use App\Actions\Simulations\ScenarioFacts;
use App\Enums\RunStatus;
use App\Models\AgentRun;
use App\Models\SimulationScenario;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateScenarioRun
{
    public function __construct(private CreateRun $create, private ScenarioFacts $facts) {}

    public function handle(User $user, SimulationScenario $scenario, string $kind, string $input, string $requestKey): AgentRun
    {
        Gate::forUser($user)->authorize('view', $scenario);
        Gate::forUser($user)->authorize('create', AgentRun::class);
        abort_unless(in_array($kind, ['scenario_analysis', 'scenario_chat'], true), 422);

        return DB::transaction(function () use ($user, $scenario, $kind, $input, $requestKey): AgentRun {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = AgentRun::query()->where('user_id', $user->id)->where('request_key', $requestKey)->first();
            if ($existing) {
                abort_unless($existing->simulation_scenario_id === $scenario->id && $existing->kind === $kind && $existing->input === $input, 409, 'Ключ запроса уже использован.');

                return $existing;
            }
            if ($scenario->runs()->where('kind', $kind)->whereIn('status', [RunStatus::Queued, RunStatus::Running])->exists()) {
                throw ValidationException::withMessages(['input' => 'Дождитесь завершения текущего ответа или отмените его.']);
            }
            $messages = [];
            $dialogue = [];
            $characters = 0;
            if ($kind === 'scenario_chat') {
                $history = $scenario->runs()->where('user_id', $user->id)->where('kind', 'scenario_chat')->where('status', RunStatus::Succeeded)->orderByDesc('created_at')->orderByDesc('id')->limit(6)->get();
                foreach ($history as $previous) {
                    $size = mb_strlen($previous->input) + mb_strlen($previous->output ?? '');
                    if ($characters + $size > 24000) {
                        break;
                    }
                    foreach (['district_id', 'alternative_id'] as $field) {
                        $previousContext = $previous->output_data['demo_context'] ?? [];
                        if (! array_key_exists($field, $dialogue)) {
                            if ($previous->driver !== 'demo') {
                                $dialogue[$field] = null;
                            } elseif (array_key_exists($field, $previousContext)) {
                                $dialogue[$field] = $previousContext[$field];
                            }
                        }
                    }
                    $characters += $size;
                    array_unshift($messages, ['role' => 'user', 'content' => $previous->input], ['role' => 'assistant', 'content' => $previous->output ?? '']);
                }
            }

            return $this->create->handle($user, $input, $requestKey, $kind, $scenario, ['facts' => $this->facts->handle($scenario), 'messages' => $messages, 'dialogue' => $dialogue]);
        });
    }
}
