<?php

namespace App\Ai\Tools;

use App\Ai\ToolBroker;
use App\Models\AgentRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class EvaluateScenario implements Tool
{
    public function __construct(protected AgentRun $run) {}

    public function name(): string
    {
        return 'evaluate_scenario';
    }

    public function description(): string
    {
        return 'Validate and calculate a complete hypothetical set of five decisions using the fixed dataset. Does not save a scenario. Returns validation errors for invalid sets.';
    }

    public function handle(Request $request): string
    {
        return json_encode(app(ToolBroker::class)->execute($this->run, $this->name(), $request->all()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['selections' => $schema->array()->items($schema->object([
            'measure_id' => $schema->string()->enum(array_column($this->run->context['facts']['catalog'] ?? [], 'id'))->required(),
            'district_id' => $schema->string()->enum(['esil', 'almaty', 'saryarka', 'baikonur', 'nura'])->nullable()->required(),
        ]))->min(5)->max(5)->required()];
    }
}
