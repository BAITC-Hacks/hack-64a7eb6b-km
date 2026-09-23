<?php

namespace App\Ai\Tools;

use App\Ai\ToolBroker;
use App\Models\AgentRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ProposeNote implements Tool
{
    public function __construct(private AgentRun $run) {}

    public function name(): string
    {
        return 'propose_note';
    }

    public function description(): string
    {
        return 'Propose a note for the user to review. This does NOT create a note: an authenticated human must approve the exact title and body. Do not claim it was saved.';
    }

    public function handle(Request $request): string
    {
        return json_encode(app(ToolBroker::class)->execute($this->run, $this->name(), $request->all()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(160)->required(),
            'body' => $schema->string()->max(8000)->required(),
        ];
    }
}
