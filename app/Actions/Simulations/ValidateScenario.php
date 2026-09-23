<?php

namespace App\Actions\Simulations;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ValidateScenario
{
    /**
     * @param  array<string, mixed>  $dataset
     * @param  array<mixed>  $selections
     * @return list<array{measure_id: string, district_id: ?string}>
     */
    public function handle(array $dataset, array $selections): array
    {
        $measures = array_column($dataset['measures'], null, 'id');
        $districts = array_column($dataset['districts'], 'id');
        $data = Validator::make(['selections' => $selections], [
            'selections' => ['required', 'array', 'list', 'size:5'],
            'selections.*' => ['required', 'array:measure_id,district_id'],
            'selections.*.measure_id' => ['required', 'string', 'distinct:strict', Rule::in(array_keys($measures))],
            'selections.*.district_id' => ['present', 'nullable', 'string', Rule::in($districts)],
        ], [
            'selections.size' => 'Выберите ровно 5 решений.',
            'selections.*.measure_id.distinct' => 'Каждое мероприятие можно выбрать только один раз.',
            'selections.*.measure_id.in' => 'Неизвестное мероприятие.',
            'selections.*.district_id.in' => 'Неизвестный район.',
        ])->validate();
        $selected = array_map(fn (array $selection): array => ['measure_id' => $selection['measure_id'], 'district_id' => $selection['district_id']], $data['selections']);
        $errors = [];
        $cost = 0;
        $directions = [];
        $byId = array_column($selected, null, 'measure_id');
        foreach ($selected as $index => $selection) {
            $measure = $measures[$selection['measure_id']];
            $cost += $measure['cost'];
            $directions[$measure['direction']] = ($directions[$measure['direction']] ?? 0) + 1;
            if ($measure['scope'] === 'district' && $selection['district_id'] === null) {
                $errors["selections.$index.district_id"] = 'Выберите район для районного мероприятия.';
            }
            if ($measure['scope'] === 'city' && $selection['district_id'] !== null) {
                $errors["selections.$index.district_id"] = 'Для общегородского мероприятия район не указывается.';
            }
        }
        if ($cost > $dataset['budget']) {
            $errors['budget'] = 'Бюджет не может превышать '.$dataset['budget'].' у. е.';
        }
        foreach ($directions as $count) {
            if ($count > 2) {
                $errors['directions'] = 'Не более 2 мероприятий из одного направления.';
            }
        }
        foreach ($dataset['incompatibilities'] as $conflict) {
            [$first, $second] = $conflict['measures'];
            if (isset($byId[$first], $byId[$second]) && (! $conflict['same_district'] || $byId[$first]['district_id'] === $byId[$second]['district_id'])) {
                $errors['conflicts.'.$first] = $conflict['message'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        usort($selected, fn (array $a, array $b): int => strnatcmp($a['measure_id'], $b['measure_id']));

        return $selected;
    }
}
