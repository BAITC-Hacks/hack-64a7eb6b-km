<?php

namespace App\Ai;

use App\Enums\RunStatus;
use App\Models\AgentRun;
use App\Models\Note;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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
            if (! in_array($name, ['search_notes', 'propose_note'], true)) {
                throw new DomainException('Tool is not allowed.');
            }

            $run->increment('tool_calls');
            $run->record('tool.started', ['tool' => $name, 'call' => $run->tool_calls]);
            $result = match ($name) {
                'search_notes' => $this->search($run, $arguments),
                'propose_note' => $this->propose($run, $arguments),
            };
            $run->record('tool.completed', ['tool' => $name, 'count' => $result['count'] ?? null, 'approval_id' => $result['approval_id'] ?? null]);

            return $result;
        });
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
