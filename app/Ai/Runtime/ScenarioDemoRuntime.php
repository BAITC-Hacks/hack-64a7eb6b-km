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
        $result = $facts['result'];
        $score = number_format((float) $result['score'], 2, ',', ' ');
        $summary = "Локальное демо, без AI-запроса. Score: {$score}. Использовано {$result['cost']} из {$facts['budget']} у. е. Осталось {$result['remaining']} у. е.";
        $critical = count($result['critical']);
        $risks = $critical > 0 ? ["Сохранилось критических показателей: {$critical}. Каждый показатель ниже 40 уменьшает Score на 1."] : ['Критических показателей нет. Следующий приоритет — район с самой низкой оценкой.'];
        if ($run->kind === 'scenario_analysis') {
            return new RunResult($summary, ['prompt_tokens' => 0, 'completion_tokens' => 0], [
                'summary' => $summary,
                'strengths' => ['Набор проходит проверку бюджета и совместимости.', 'Эффекты рассчитаны с учётом горизонта 8 кварталов.'],
                'risks' => $risks,
                'tradeoffs' => ['30% оценки зависит от самого слабого района.', 'Более долгий лаг уменьшает реализованный эффект. Неизрасходованный бюджет не даёт бонуса.'],
                'recommendations' => array_map(fn (array $alternative): array => ['alternative_id' => $alternative['id'], 'reason' => 'Проверенная замена улучшает итоговый Score; сравните влияние на районы.'], $facts['alternatives']),
            ]);
        }
        if (preg_match('/предлож|улучш|сохран|вариант/iu', $run->input) && isset($facts['alternatives'][0])) {
            $proposal = $this->tools->execute($run, 'propose_scenario', ['selections' => $facts['alternatives'][0]['selections']]);
            $summary .= "\nПодготовлен проверенный вариант. Подтвердите предложение ниже, чтобы создать новый сценарий. Исходный сценарий сохранится. Номер предложения: {$proposal['approval_id']}.";
        } else {
            $this->tools->execute($run, 'evaluate_scenario', ['selections' => $facts['selections']]);
            $summary .= "\n".implode(' ', $risks)."\nЭто шаблонный ответ деморежима. Можно спросить о бюджете или попросить предложить улучшение; свободный диалог доступен при подключении AI.";
        }

        return new RunResult($summary, ['prompt_tokens' => 0, 'completion_tokens' => 0]);
    }
}
