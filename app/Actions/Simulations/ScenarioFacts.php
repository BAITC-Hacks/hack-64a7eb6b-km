<?php

namespace App\Actions\Simulations;

use App\Models\SimulationScenario;

class ScenarioFacts
{
    public function __construct(private CalculateScenario $calculate) {}

    /** @return array<string, mixed> */
    public function handle(SimulationScenario $scenario): array
    {
        $data = $scenario->dataset->data;
        $measures = array_column($data['measures'], null, 'id');
        $districts = array_column($data['districts'], null, 'id');

        return [
            'dataset_version' => $scenario->dataset->version,
            'calculator_version' => $scenario->calculator_version,
            'budget' => $data['budget'], 'horizon' => $data['horizon'],
            'score_formula' => ['population_average_weight' => '0.70', 'worst_district_weight' => '0.30', 'penalty_per_indicator_below_40' => 1],
            'selected_decisions' => array_map(fn (array $selection): array => [
                'measure_id' => $selection['measure_id'], 'name' => $measures[$selection['measure_id']]['name'],
                'district' => $selection['district_id'] === null ? 'Весь город' : $districts[$selection['district_id']]['name'],
            ], $scenario->selections),
            'indicators' => $data['indicators'], 'catalog' => $data['measures'],
            'incompatibilities' => $data['incompatibilities'], 'available_synergies' => $data['synergies'],
            'selections' => $scenario->selections, 'result' => $scenario->result,
            'baseline' => $this->calculate->handle($data, [], $scenario->calculator_version),
            'alternatives' => array_map(fn (array $alternative): array => [
                'id' => $alternative['id'], 'selections' => $alternative['selections'],
                'score' => $alternative['result']['score'], 'cost' => $alternative['result']['cost'],
                'delta' => $alternative['delta'], 'removed' => $alternative['removed'], 'added' => $alternative['added'],
            ], $scenario->alternatives),
        ];
    }
}
