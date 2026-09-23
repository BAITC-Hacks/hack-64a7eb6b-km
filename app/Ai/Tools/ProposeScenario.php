<?php

namespace App\Ai\Tools;

class ProposeScenario extends EvaluateScenario
{
    public function name(): string
    {
        return 'propose_scenario';
    }

    public function description(): string
    {
        return 'Validate, calculate and persist an exact proposed variant of the current scenario. Does NOT create a scenario. The authenticated owner must separately approve it after this run succeeds.';
    }
}
