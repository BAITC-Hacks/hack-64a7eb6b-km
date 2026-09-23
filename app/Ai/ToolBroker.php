<?php

namespace App\Ai;

use App\Actions\Simulations\CalculateScenario;
use App\Actions\Simulations\ValidateScenario;
use App\Enums\RunStatus;
use App\Models\AgentRun;
use App\Models\Note;
use App\Models\SimulationScenario;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ToolBroker
{
    /**
     * The only application capabilities exposed to models. Never accept a user ID,
     * SQL statement, URL, path, PHP class, or shell command from a model.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(AgentRun $run, string $name, array $arguments): array
    {
        return DB::transaction(function () use ($run, $name, $arguments): array {
            $run = AgentRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($run->status !== RunStatus::Running) {
                throw new DomainException('Run is no longer running.');
            }
            if ($run->tool_calls >= $run->limits['max_tool_calls']) {
                throw new DomainException('Tool call limit reached.');
            }
            $allowed = match ($run->kind) {
                'workspace' => ['search_notes', 'propose_note'],
                'scenario_chat' => ['evaluate_scenario', 'propose_scenario'],
                default => [],
            };
            if (! in_array($name, $allowed, true)) {
                throw new DomainException('Tool is not allowed.');
            }

            $run->increment('tool_calls');
            $run->record('tool.started', ['tool' => $name, 'call' => $run->tool_calls]);
            $result = match ($name) {
                'search_notes' => $this->search($run, $arguments),
                'propose_note' => $this->propose($run, $arguments),
                'evaluate_scenario', 'propose_scenario' => $this->simulate($run, $arguments, $name === 'propose_scenario'),
            };
            $run->record('tool.completed', ['tool' => $name, 'count' => $result['count'] ?? null, 'approval_id' => $result['approval_id'] ?? null, 'evaluation' => $name === 'evaluate_scenario' ? $result : null]);

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function simulate(AgentRun $run, array $arguments, bool $propose): array
    {
        $scenario = SimulationScenario::query()->where('user_id', $run->user_id)->findOrFail($run->simulation_scenario_id);
        if ($scenario->dataset->version !== ($run->context['facts']['dataset_version'] ?? null)) {
            throw new DomainException('Scenario context does not match the dataset.');
        }
        try {
            if (array_diff(array_keys($arguments), ['selections']) !== []) {
                throw ValidationException::withMessages(['selections' => 'Допустим только полный набор selections.']);
            }
            $data = Validator::make($arguments, ['selections' => ['required', 'array', 'max:5']])->validate();
            $selections = app(ValidateScenario::class)->handle($scenario->dataset->data, $data['selections']);
        } catch (ValidationException $exception) {
            return ['valid' => false, 'errors' => $exception->errors()];
        }
        $result = app(CalculateScenario::class)->handle($scenario->dataset->data, $selections, $scenario->calculator_version);
        if (! $propose) {
            return ['valid' => true, 'selections' => $selections, 'result' => $result, 'delta' => bcsub($result['score'], $scenario->result['score'], 8)];
        }
        $hash = hash('sha256', json_encode($selections, JSON_THROW_ON_ERROR));
        $approval = $run->approvals()->where('tool', 'propose_scenario')->where('arguments->payload_hash', $hash)->first();
        if (! $approval) {
            $approval = $run->approvals()->create(['tool' => 'propose_scenario', 'arguments' => [
                'title' => 'Вариант: '.mb_substr($scenario->title, 0, 150),
                'source_scenario_id' => $scenario->id, 'dataset_id' => $scenario->simulation_dataset_id,
                'calculator_version' => $scenario->calculator_version,
                'selections' => $selections, 'result' => $result, 'payload_hash' => $hash,
            ]]);
            $run->record('approval.requested', ['approval_id' => $approval->id, 'tool' => 'propose_scenario']);
        }

        return ['valid' => true, 'approval_id' => $approval->id, 'status' => 'pending_human_approval', 'scenario_created' => false, 'result' => $result];
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function search(AgentRun $run, array $arguments): array
    {
        /** @var array{query: string} $data */
        $data = Validator::make($arguments, ['query' => ['present', 'string', 'max:200']])->validate();
        $query = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $data['query']);
        $notes = Note::query()->where('user_id', $run->user_id)
            ->where(fn ($builder) => $builder->whereLike('title', '%'.$query.'%')->orWhereLike('body', '%'.$query.'%'))
            ->latest()->limit(5)->get()
            ->map(fn (Note $note): array => ['id' => $note->id, 'title' => $note->title, 'body' => Str::limit($note->body, 1500)])
            ->all();

        return ['notes' => $notes, 'count' => count($notes), 'content_is_untrusted' => true];
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function propose(AgentRun $run, array $arguments): array
    {
        /** @var array{title: string, body: string} $data */
        $data = Validator::make($arguments, [
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:8000'],
        ])->validate();
        $approval = $run->approvals()->create(['tool' => 'propose_note', 'arguments' => $data]);
        $run->record('approval.requested', ['approval_id' => $approval->id, 'tool' => 'propose_note']);

        return ['approval_id' => $approval->id, 'status' => 'pending_human_approval', 'note_created' => false];
    }
}
