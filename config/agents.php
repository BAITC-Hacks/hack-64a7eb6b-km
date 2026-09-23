<?php

return [
    'driver' => env('AGENT_DRIVER', 'demo'),
    'provider' => env('AGENT_PROVIDER', 'openai'),
    'model' => env('AGENT_MODEL', 'gpt-5.4-nano'),
    'max_steps' => max(1, min(10, (int) env('AGENT_MAX_STEPS', 5))),
    'max_tokens' => max(128, min(8192, (int) env('AGENT_MAX_TOKENS', 2048))),
    'max_tool_calls' => max(1, min(20, (int) env('AGENT_MAX_TOOL_CALLS', 8))),
    'timeout' => max(10, min(150, (int) env('AGENT_TIMEOUT', 120))),
    'queue' => 'agents',
    'prompt_version' => 'workspace-v1',
];
