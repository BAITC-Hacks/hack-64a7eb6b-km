<?php

namespace App\Actions\Simulations;

use Illuminate\Validation\ValidationException;

class FindScenarioAlternatives
{
    public function __construct(private ValidateScenario $validate, private CalculateScenario $calculate) {}

    /**
     * @param  array<string, mixed>  $dataset
     * @param  list<array{measure_id: string, district_id: ?string}>  $selections
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    public function handle(array $dataset, array $selections, array $result): array
    {
        $alternatives = [];
        foreach ($selections as $index => $removed) {
            foreach ($dataset['measures'] as $measure) {
                $districts = $measure['scope'] === 'city' ? [null] : array_column($dataset['districts'], 'id');
                foreach ($districts as $district) {
                    $candidate = $selections;
                    $candidate[$index] = ['measure_id' => $measure['id'], 'district_id' => $district];
                    try {
                        $candidate = $this->validate->handle($dataset, $candidate);
                    } catch (ValidationException) {
                        continue;
                    }
                    $calculated = $this->calculate->handle($dataset, $candidate);
                    if (bccomp($calculated['score'], $result['score'], 8) <= 0) {
                        continue;
                    }
                    $id = hash('sha256', json_encode($candidate, JSON_THROW_ON_ERROR));
                    $alternatives[$id] = [
                        'id' => $id, 'selections' => $candidate, 'result' => $calculated,
                        'delta' => bcsub($calculated['score'], $result['score'], 8),
                        'removed' => $removed, 'added' => ['measure_id' => $measure['id'], 'district_id' => $district],
                    ];
                }
            }
        }
        $alternatives = array_values($alternatives);
        usort($alternatives, fn (array $a, array $b): int => bccomp($b['delta'], $a['delta'], 8) ?: ($a['result']['cost'] <=> $b['result']['cost']) ?: strcmp($a['id'], $b['id']));

        return array_slice($alternatives, 0, 3);
    }
}
