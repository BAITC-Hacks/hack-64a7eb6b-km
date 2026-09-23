<?php

namespace App\Actions\Simulations;

use App\Models\SimulationScenario;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class CompareScenarios
{
    /** @return array<string, mixed> */
    public function handle(User $user, SimulationScenario $left, SimulationScenario $right): array
    {
        Gate::forUser($user)->authorize('view', $left);
        Gate::forUser($user)->authorize('view', $right);
        abort_unless($left->simulation_dataset_id === $right->simulation_dataset_id && $left->calculator_version === $right->calculator_version, 422, 'Можно сравнивать только сценарии одной версии данных и расчёта.');
        $districts = [];
        $rightDistricts = array_column($right->result['districts'], null, 'id');
        foreach ($left->result['districts'] as $district) {
            $other = $rightDistricts[$district['id']];
            $indicators = [];
            foreach ($district['indicators'] as $key => $value) {
                $indicators[$key] = bcsub($other['indicators'][$key], $value, 8);
            }
            $districts[] = ['id' => $district['id'], 'name' => $district['name'], 'score' => bcsub($other['score'], $district['score'], 8), 'indicators' => $indicators];
        }

        return [
            'score' => bcsub($right->result['score'], $left->result['score'], 8),
            'cost' => $right->result['cost'] - $left->result['cost'],
            'minimum' => bcsub($right->result['minimum'], $left->result['minimum'], 8),
            'critical' => count($right->result['critical']) - count($left->result['critical']),
            'districts' => $districts,
        ];
    }
}
