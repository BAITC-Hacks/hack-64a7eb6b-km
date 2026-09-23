<?php

namespace App\Actions\Simulations;

use DomainException;

class CalculateScenario
{
    public const VERSION = 'quality-of-life-v1';

    private const SCALE = 8;

    /**
     * Inputs are validated by the caller; an empty set is reserved for the baseline.
     *
     * @param  array<string, mixed>  $dataset
     * @param  list<array{measure_id: string, district_id: ?string}>  $selections
     * @return array<string, mixed>
     */
    public function handle(array $dataset, array $selections, string $version = self::VERSION): array
    {
        if ($version !== self::VERSION) {
            throw new DomainException('Unsupported calculator version.');
        }
        $districts = array_column($dataset['districts'], null, 'id');
        $measures = array_column($dataset['measures'], null, 'id');
        $effects = [];
        $synergies = [];
        $cost = 0;
        foreach ($selections as $selection) {
            $measure = $measures[$selection['measure_id']];
            $cost += $measure['cost'];
            $factor = bcdiv((string) ($dataset['horizon'] - $measure['lag']), (string) $dataset['horizon'], self::SCALE);
            foreach ($districts as $id => &$district) {
                if ($measure['scope'] === 'district' && $id !== $selection['district_id']) {
                    continue;
                }
                foreach ($measure['effects'] as $key => $value) {
                    $delta = bcmul($this->number($value), $factor, self::SCALE);
                    $district['indicators'][$key] = bcadd($this->number($district['indicators'][$key]), $delta, self::SCALE);
                    $effects[] = ['measure_id' => $measure['id'], 'district_id' => $id, 'indicator' => $key, 'delta' => $delta];
                }
            }
            unset($district);
        }
        $selected = array_column($selections, null, 'measure_id');
        foreach ($dataset['synergies'] as $synergy) {
            [$first, $second] = $synergy['measures'];
            if (! isset($selected[$first], $selected[$second])) {
                continue;
            }
            $id = $selected[$first]['district_id'];
            $key = $synergy['indicator'];
            $districts[$id]['indicators'][$key] = bcadd($this->number($districts[$id]['indicators'][$key]), $this->number($synergy['bonus']), self::SCALE);
            $synergies[] = ['measures' => [$first, $second], 'district_id' => $id, 'indicator' => $key, 'delta' => (string) $synergy['bonus']];
        }
        $average = '0';
        $minimum = '100';
        $critical = [];
        $clipping = [];
        foreach ($districts as $id => &$district) {
            $score = '0';
            foreach ($district['indicators'] as $key => &$value) {
                $beforeClip = $this->number($value);
                $value = bccomp($beforeClip, '0', self::SCALE) < 0 ? '0' : (bccomp($beforeClip, '100', self::SCALE) > 0 ? '100' : $beforeClip);
                $value = bcadd($value, '0', self::SCALE);
                if (bccomp($value, $beforeClip, self::SCALE) !== 0) {
                    $clipping[] = ['district_id' => $id, 'indicator' => $key, 'delta' => bcsub($value, $beforeClip, self::SCALE)];
                }
                $score = bcadd($score, bcmul($value, $dataset['indicators'][$key]['weight'], self::SCALE), self::SCALE);
                if (bccomp($value, '40', self::SCALE) < 0) {
                    $critical[] = ['district_id' => $id, 'indicator' => $key, 'value' => $value];
                }
            }
            unset($value);
            $district['score'] = $score;
            $average = bcadd($average, bcmul($score, $district['population'], self::SCALE), self::SCALE);
            if (bccomp($score, $minimum, self::SCALE) < 0) {
                $minimum = $score;
            }
        }
        unset($district);
        $score = bcsub(bcadd(bcmul('0.7', $average, self::SCALE), bcmul('0.3', $minimum, self::SCALE), self::SCALE), (string) count($critical), self::SCALE);

        return [
            'score' => $score, 'average' => $average, 'minimum' => $minimum,
            'cost' => $cost, 'remaining' => $dataset['budget'] - $cost,
            'critical' => $critical, 'districts' => array_values($districts),
            'effects' => $effects, 'synergies' => $synergies, 'clipping' => $clipping,
        ];
    }

    /** @return numeric-string */
    private function number(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new DomainException('Dataset contains a non-numeric indicator.');
        }

        return (string) $value;
    }
}
