<?php

namespace App\Ai\Runtime;

use App\Models\AgentRun;

interface AgentRuntime
{
    public function execute(AgentRun $run): RunResult;
}
