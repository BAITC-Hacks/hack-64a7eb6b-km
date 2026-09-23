<?php

namespace App\Ai\Runtime;

use App\Ai\ToolBroker;
use App\Models\AgentRun;

class ScenarioDemoRuntime implements AgentRuntime
{
    public function __construct(private ToolBroker $tools) {}

    public function execute(AgentRun $run): RunResult
    {
        $facts = $run->context['facts'];
        if ($run->kind === 'scenario_analysis') {
            return $this->analysis($facts);
        }

        $question = str_replace('ё', 'е', mb_strtolower($run->input));
        $remembered = $run->context['dialogue'] ?? [];
        $state = [];
        $numbers = $this->alternativeNumbers($question);
        if (count($numbers) > 1) {
            return $this->reply('Уточните один номер варианта для расчёта или подготовки предложения.', ['alternative_id' => null]);
        }
        $districts = $this->districts($question, $facts);
        if (count($districts) === 1) {
            $state['district_id'] = $districts[0];
            $state['alternative_id'] = null;
        } elseif (count($districts) > 1) {
            $state['district_id'] = null;
            $state['alternative_id'] = null;
        } elseif (preg_match('/\b(нем|него|этого района|этом районе|там)\b/u', $question)) {
            $districtId = $remembered['district_id'] ?? null;
            if (! in_array($districtId, array_column($facts['result']['districts'], 'id'), true)) {
                return $this->reply('Уточните район: '.implode(', ', array_column($facts['result']['districts'], 'name')).'.', $state);
            }
            $districts = [$districtId];
            $state['district_id'] = $districtId;
        }

        $number = $numbers[0] ?? null;
        $reference = (bool) preg_match('/\b(его|этот вариант|этого варианта|этот|выбранный)\b/u', $question);
        $alternative = null;
        if ($number !== null) {
            $alternative = $facts['alternatives'][$number - 1] ?? null;
            if ($alternative === null) {
                return $this->reply('Такого варианта нет. '.$this->alternatives($facts), $state);
            }
        } elseif ($reference && $districts === []) {
            foreach ($facts['alternatives'] as $candidate) {
                if ($candidate['id'] === ($remembered['alternative_id'] ?? null)) {
                    $alternative = $candidate;
                    break;
                }
            }
        }
        if ($alternative !== null) {
            $state['alternative_id'] = $alternative['id'];
            $state['district_id'] = null;
        }

        $prepare = (bool) preg_match('/\b(подготов\p{L}*|сохран\p{L}*|созда\p{L}*|предложи\p{L}*|оформи\p{L}*)\b/u', $question);
        $negated = (bool) preg_match('/\bне\s+(?:надо\s+|нужно\s+)?(?:подгот\p{L}*|сохран\p{L}*|созда\p{L}*|предлаг\p{L}*|предлож\p{L}*|оформ\p{L}*)/u', $question);
        if ($prepare && ! $negated) {
            if ($facts['alternatives'] === []) {
                return $this->reply($this->alternatives($facts), $state);
            }
            if ($alternative === null) {
                if ($reference || ! preg_match('/лучш|улучш/u', $question)) {
                    return $this->reply('Какой вариант подготовить? Укажите номер или попросите подготовить лучший вариант. '.$this->alternatives($facts), $state);
                }
                $alternative = $facts['alternatives'][0];
                $state['alternative_id'] = $alternative['id'];
                $state['district_id'] = null;
            }
            $evaluation = $this->tools->execute($run, 'evaluate_scenario', ['selections' => $alternative['selections']]);
            if (! $evaluation['valid']) {
                return $this->reply('Вариант не прошёл проверку ограничений. Предложение не создано.', $state);
            }
            $proposal = $this->tools->execute($run, 'propose_scenario', ['selections' => $alternative['selections']]);
            if (! $proposal['valid']) {
                return $this->reply('Не удалось подготовить допустимое предложение. Сценарий не изменён.', $state);
            }

            return $this->reply($this->summary($facts, $evaluation['result'])."\nПодготовлен проверенный вариант. Нажмите «Создать этот вариант» в карточке предложения, чтобы сохранить отдельный сценарий. Сообщение в чате не является подтверждением.", $state);
        }

        if ($reference && $districts === [] && $alternative === null) {
            return $this->reply('Уточните, о каком районе или номере варианта идёт речь.', $state);
        }
        if ($alternative !== null) {
            $evaluation = $this->tools->execute($run, 'evaluate_scenario', ['selections' => $alternative['selections']]);
            if (! $evaluation['valid']) {
                return $this->reply('Вариант не прошёл проверку ограничений.', $state);
            }
            $index = array_search($alternative['id'], array_column($facts['alternatives'], 'id'), true) + 1;

            return $this->reply("Вариант {$index}: ".$this->selection($facts, $alternative['removed']).' → '.$this->selection($facts, $alternative['added']).".\n".$this->summary($facts, $evaluation['result'])."\nИзменение Score относительно текущего сценария: ".$this->number($evaluation['delta']).".\n".$this->risks($facts, $evaluation['result'])."\nЧтобы подготовить предложение на подтверждение, напишите «Подготовь этот вариант».", $state);
        }

        $parts = [];
        if (preg_match('/бюджет|стоим|затрат|расход|денег|остат|остал/u', $question)) {
            $parts[] = "Бюджет: {$facts['result']['cost']} из {$facts['budget']} у. е. Остаток: {$facts['result']['remaining']} у. е. Неизрасходованный бюджет не добавляет баллов.";
        }
        if (preg_match('/score|оценк|балл|почему|измен|итог|результат/u', $question)) {
            $parts[] = $this->summary($facts, $facts['result']);
        }
        if ($districts !== [] || preg_match('/район|показател/u', $question)) {
            foreach ($facts['result']['districts'] as $district) {
                if ($districts === [] || in_array($district['id'], $districts, true)) {
                    $parts[] = $this->district($facts, $district);
                }
            }
        }
        if (preg_match('/риск|критич|слаб|проблем|компромисс/u', $question)) {
            $parts[] = $this->risks($facts, $facts['result'], $districts);
        }
        if (preg_match('/срок|лаг|квартал|долго|когда|эффект/u', $question)) {
            $parts[] = $this->lags($facts);
        }
        if (preg_match('/синерги|совмест|сочета/u', $question)) {
            $parts[] = $this->synergies($facts);
        }
        if (preg_match('/улучш|альтернатив|вариант|замен/u', $question)) {
            $parts[] = $this->alternatives($facts);
        }
        if ($parts === []) {
            $parts[] = 'Я работаю в демо-режиме с расчётами вашего сценария. Могу объяснить бюджет, Score, районы, риски, сроки эффекта и синергии, сравнить варианты и подготовить предложение. Например: «Какие риски остаются?», «Покажи варианты улучшения», «Подготовь лучший вариант». Свободные ответы на другие темы доступны с подключённым AI.';
        }

        return $this->reply(implode("\n\n", $parts), $state);
    }

    /** @param array<string, mixed> $state */
    private function reply(string $text, array $state): RunResult
    {
        return new RunResult($text, ['prompt_tokens' => 0, 'completion_tokens' => 0], ['demo_context' => $state]);
    }

    /** @param array<string, mixed> $facts */
    private function analysis(array $facts): RunResult
    {
        $summary = $this->summary($facts, $facts['result']);

        return new RunResult($summary, ['prompt_tokens' => 0, 'completion_tokens' => 0], [
            'summary' => $summary,
            'strengths' => ['Набор проходит проверку бюджета и совместимости.', $this->synergies($facts)],
            'risks' => [$this->risks($facts, $facts['result'])],
            'tradeoffs' => ['70% оценки зависит от среднего по населению, 30% — от худшего района. Каждый показатель ниже 40 уменьшает Score на 1.', $this->lags($facts)],
            'recommendations' => array_map(fn (array $alternative): array => ['alternative_id' => $alternative['id'], 'reason' => $this->selection($facts, $alternative['removed']).' → '.$this->selection($facts, $alternative['added']).'. Прирост Score: '.$this->number($alternative['delta']).'.'], $facts['alternatives']),
        ]);
    }

    /** @param array<string, mixed> $facts
     * @param  array<string, mixed>  $result
     */
    private function summary(array $facts, array $result): string
    {
        $delta = bcsub($result['score'], $facts['baseline']['score'], 8);

        return 'Score: '.$this->number($facts['baseline']['score']).' → '.$this->number($result['score']).' (изменение '.$this->number($delta)."). Расходы: {$result['cost']} / {$facts['budget']}, остаток: {$result['remaining']} у. е.\nФормула: 70% среднего по населению + 30% худшего района − число показателей ниже 40. Данные синтетические, это не прогноз реального города.";
    }

    /** @param array<string, mixed> $facts
     * @return list<string>
     */
    private function districts(string $question, array $facts): array
    {
        $patterns = ['esil' => 'есил', 'almaty' => 'алмат', 'saryarka' => 'сарыарк', 'baikonur' => 'байкон', 'nura' => 'нур'];
        $ids = [];
        foreach ($facts['result']['districts'] as $district) {
            $stem = $patterns[$district['id']] ?? preg_quote(mb_strtolower($district['name']), '/');
            if (preg_match('/\b(?:'.$stem.'\p{L}*|'.preg_quote($district['id'], '/').')\b/u', $question)) {
                $ids[] = $district['id'];
            }
        }

        return $ids;
    }

    /** @return list<int> */
    private function alternativeNumbers(string $question): array
    {
        preg_match_all('/вариант\p{L}*\s*№?\s*(\d+)/u', $question, $matches);
        $numbers = array_map(intval(...), $matches[1]);
        foreach (['перв', 'втор', 'трет'] as $index => $stem) {
            if (preg_match('/\b'.$stem.'\p{L}*\b/u', $question)) {
                $numbers[] = $index + 1;
            }
        }

        return array_values(array_unique($numbers));
    }

    /** @param array<string, mixed> $facts
     * @param  array<string, mixed>  $district
     */
    private function district(array $facts, array $district): string
    {
        $before = array_column($facts['baseline']['districts'], null, 'id')[$district['id']];
        $lines = [$district['name'].': индекс '.$this->number($before['score']).' → '.$this->number($district['score']).'.'];
        foreach ($district['indicators'] as $id => $value) {
            $lines[] = $facts['indicators'][$id]['name'].': '.$this->number($before['indicators'][$id]).' → '.$this->number($value).'.';
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $facts
     * @param  array<string, mixed>  $result
     * @param  list<string>  $districts
     */
    private function risks(array $facts, array $result, array $districts = []): string
    {
        $names = array_column($result['districts'], 'name', 'id');
        $critical = array_filter($result['critical'], fn (array $item): bool => $districts === [] || in_array($item['district_id'], $districts, true));
        $lines = [];
        foreach ($critical as $item) {
            $lines[] = $names[$item['district_id']].' — '.$facts['indicators'][$item['indicator']]['name'].': '.$this->number($item['value']).' (ниже 40).';
        }
        if ($lines === []) {
            $lines[] = 'Критических показателей ниже 40'.($districts !== [] ? ' в выбранном районе' : '').' нет.';
        }
        $worst = $result['districts'][0];
        foreach ($result['districts'] as $district) {
            if (bccomp($district['score'], $worst['score'], 8) < 0) {
                $worst = $district;
            }
        }
        $lines[] = 'Самый слабый район города: '.$worst['name'].', индекс '.$this->number($worst['score']).'. Его вес в Score — 30%.';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $facts */
    private function lags(array $facts): string
    {
        $catalog = array_column($facts['catalog'], null, 'id');
        $lines = ["Горизонт расчёта — {$facts['horizon']} кварталов. Реализованная доля эффекта: (горизонт − лаг) / горизонт; она уже учтена в показателях."];
        foreach ($facts['selections'] as $selection) {
            $measure = $catalog[$selection['measure_id']];
            $lines[] = $measure['name'].": лаг {$measure['lag']} квартала.";
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $facts */
    private function synergies(array $facts): string
    {
        if ($facts['result']['synergies'] === []) {
            return 'В текущем наборе нет сработавших синергий.';
        }
        $districts = array_column($facts['result']['districts'], 'name', 'id');
        $catalog = array_column($facts['catalog'], 'name', 'id');
        $lines = ['Сработавшие синергии уже включены в расчёт:'];
        foreach ($facts['result']['synergies'] as $synergy) {
            $measures = implode(' + ', array_map(fn (string $id): string => $catalog[$id], $synergy['measures']));
            $lines[] = $measures.'; '.$districts[$synergy['district_id']].' — '.$facts['indicators'][$synergy['indicator']]['name'].': '.$this->number($synergy['delta']).'.';
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $facts */
    private function alternatives(array $facts): string
    {
        if ($facts['alternatives'] === []) {
            return 'Улучшающих замен одной меры не найдено. Это не доказывает глобальную оптимальность сценария.';
        }
        $lines = ['Проверенные улучшения одной заменой:'];
        foreach ($facts['alternatives'] as $index => $alternative) {
            $lines[] = 'Вариант '.($index + 1).': '.$this->selection($facts, $alternative['removed']).' → '.$this->selection($facts, $alternative['added']).'. Score '.$this->number($alternative['score']).' (прирост '.$this->number($alternative['delta'])."); бюджет {$alternative['cost']} / {$facts['budget']}.";
        }
        $lines[] = 'Можно спросить «Сравни второй вариант» или «Подготовь вариант 2». Создание требует отдельного подтверждения.';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $facts
     * @param  array{measure_id: string, district_id: string|null}  $selection
     */
    private function selection(array $facts, array $selection): string
    {
        $name = array_column($facts['catalog'], 'name', 'id')[$selection['measure_id']];
        $district = $selection['district_id'] === null ? 'Весь город' : array_column($facts['result']['districts'], 'name', 'id')[$selection['district_id']];

        return $name.' ('.$district.')';
    }

    private function number(string $value): string
    {
        return number_format((float) $value, 2, ',', ' ');
    }
}
