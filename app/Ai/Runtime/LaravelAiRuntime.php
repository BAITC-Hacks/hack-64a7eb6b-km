<?php

namespace App\Ai\Runtime;

use App\Ai\Agents\ScenarioAnalysisAgent;
use App\Ai\Agents\ScenarioChatAgent;
use App\Ai\Agents\WorkspaceAgent;
use App\Models\AgentRun;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Ai\Responses\StructuredAgentResponse;
use LogicException;

class LaravelAiRuntime implements AgentRuntime
{
    public function execute(AgentRun $run): RunResult
    {
        if (config('agents.driver') !== 'laravel') {
            throw new LogicException('Live AI is disabled.');
        }
        $agentClass = match ($run->kind) {
            'workspace' => WorkspaceAgent::class,
            'scenario_analysis' => ScenarioAnalysisAgent::class,
            'scenario_chat' => ScenarioChatAgent::class,
            default => throw new LogicException('Unknown agent kind.'),
        };
        if (app()->environment('testing') && ! $agentClass::isFaked()) {
            throw new LogicException('Tests must fake the agent; live requests are forbidden.');
        }
        if (! $agentClass::isFaked() && blank(config('ai.providers.'.$run->provider.'.key'))) {
            throw new LogicException('AI provider key is not configured.');
        }
        $input = $run->kind === 'scenario_analysis'
            ? $run->input."\n".json_encode($run->context['facts'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            : $run->input;
        $response = (new $agentClass($run))->prompt($input, provider: $run->provider, model: $run->model, timeout: $run->limits['timeout']);
        $data = null;
        if ($run->kind === 'scenario_analysis') {
            if (! $response instanceof StructuredAgentResponse) {
                throw new LogicException('Expected structured analysis.');
            }
            $validator = Validator::make($response->toArray(), [
                'summary' => ['required', 'string', 'max:6000'],
                'strengths' => ['present', 'array', 'max:4'], 'strengths.*' => ['string', 'max:3000'],
                'risks' => ['present', 'array', 'max:4'], 'risks.*' => ['string', 'max:3000'],
                'tradeoffs' => ['present', 'array', 'max:4'], 'tradeoffs.*' => ['string', 'max:3000'],
                'recommendations' => ['present', 'array', 'max:3'],
                'recommendations.*.alternative_id' => ['required', Rule::in(array_column($run->context['facts']['alternatives'] ?? [], 'id'))],
                'recommendations.*.reason' => ['required', 'string', 'max:3000'],
            ]);
            if ($validator->fails()) {
                $run->update(['usage' => $response->usage->toArray()]);
                $run->record('analysis.invalid_response', ['fields' => array_values(array_unique(array_map(fn (string $field): string => explode('.', $field)[0], array_keys($validator->failed()))))]);
            }
            $data = $validator->validate();
        }

        return new RunResult($data['summary'] ?? $response->text, $response->usage->toArray(), $data);
    }
}
