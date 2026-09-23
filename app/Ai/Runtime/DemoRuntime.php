<?php

namespace App\Ai\Runtime;

use App\Ai\ToolBroker;
use App\Models\AgentRun;
use Illuminate\Support\Str;

class DemoRuntime implements AgentRuntime
{
    public function __construct(private ToolBroker $tools) {}

    public function execute(AgentRun $run): RunResult
    {
        if ($run->kind !== 'workspace') {
            return app(ScenarioDemoRuntime::class)->execute($run);
        }
        $notes = $this->tools->execute($run, 'search_notes', ['query' => '']);
        $this->tools->execute($run, 'propose_note', [
            'title' => Str::limit('Демо: '.$run->input, 150),
            'body' => "Задача: {$run->input}\n\nЭто демонстрационная заметка. Её предложил локальный сценарий, а не AI-модель. Подтвердите сохранение или отклоните предложение.",
        ]);

        return new RunResult(
            "Демо-режим — без обращения к AI.\n\nНайдено ваших заметок: {$notes['count']}. Подготовлено предложение новой заметки. Оно появится в базе только после вашего подтверждения ниже.",
            ['prompt_tokens' => 0, 'completion_tokens' => 0],
        );
    }
}
