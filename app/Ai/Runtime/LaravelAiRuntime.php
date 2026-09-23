<?php

namespace App\Ai\Runtime;

use App\Ai\Agents\WorkspaceAgent;
use App\Models\AgentRun;
use LogicException;

class LaravelAiRuntime implements AgentRuntime
{
    public function execute(AgentRun $run): RunResult
    {
        if (config('agents.driver') !== 'laravel') {
            throw new LogicException('Live AI is disabled.');
        }
        if (app()->environment('testing') && ! WorkspaceAgent::isFaked()) {
            throw new LogicException('Tests must fake WorkspaceAgent; live requests are forbidden.');
        }
        if (! WorkspaceAgent::isFaked() && blank(config('ai.providers.'.$run->provider.'.key'))) {
            throw new LogicException('AI provider key is not configured.');
        }

        $response = (new WorkspaceAgent($run))->prompt(
            $run->input,
            provider: $run->provider,
            model: $run->model,
            timeout: $run->limits['timeout'],
        );

        return new RunResult($response->text, $response->usage->toArray());
    }
}
