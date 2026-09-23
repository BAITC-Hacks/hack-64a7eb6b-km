<?php

namespace App\Actions\Simulations;

use App\Models\SimulationDataset;
use App\Models\SimulationScenario;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateScenario
{
    public function __construct(private ValidateScenario $validate, private CalculateScenario $calculate, private FindScenarioAlternatives $alternatives) {}

    /** @param array<mixed> $selections */
    public function handle(User $user, SimulationDataset $dataset, array $selections, string $title, ?string $requestKey, ?SimulationScenario $source = null, ?string $approvalId = null): SimulationScenario
    {
        Gate::forUser($user)->authorize('create', SimulationScenario::class);
        if ($source) {
            Gate::forUser($user)->authorize('view', $source);
            abort_unless($source->simulation_dataset_id === $dataset->id && $source->calculator_version === CalculateScenario::VERSION, 422, 'Версии сценария несовместимы.');
        }
        $selected = $this->validate->handle($dataset->data, $selections);
        $result = $this->calculate->handle($dataset->data, $selected);
        $alternatives = $this->alternatives->handle($dataset->data, $selected, $result);

        return DB::transaction(function () use ($user, $dataset, $selected, $title, $requestKey, $source, $approvalId, $result, $alternatives): SimulationScenario {
            if ($approvalId === null) {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $existing = SimulationScenario::query()->where('user_id', $user->id)->where('request_key', $requestKey)->first();
                if ($existing) {
                    if ($existing->selections !== $selected || $existing->title !== $title || $existing->simulation_dataset_id !== $dataset->id) {
                        throw ValidationException::withMessages(['request_key' => 'Этот запрос уже использован для другого сценария.']);
                    }

                    return $existing;
                }
            }

            return SimulationScenario::query()->create([
                'user_id' => $user->id, 'simulation_dataset_id' => $dataset->id,
                'source_scenario_id' => $source?->id, 'tool_approval_id' => $approvalId,
                'request_key' => $requestKey, 'title' => $title,
                'calculator_version' => CalculateScenario::VERSION,
                'selections' => $selected, 'result' => $result, 'alternatives' => $alternatives,
            ]);
        });
    }
}
