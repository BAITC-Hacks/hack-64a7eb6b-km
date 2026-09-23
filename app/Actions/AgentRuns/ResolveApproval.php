<?php

namespace App\Actions\AgentRuns;

use App\Enums\RunStatus;
use App\Models\AgentRun;
use App\Models\Note;
use App\Models\ToolApproval;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ResolveApproval
{
    public function handle(User $user, ToolApproval $approval, bool $approve): void
    {
        DB::transaction(function () use ($user, $approval, $approve): void {
            // All mutations lock run before approval to share a consistent lock order.
            $run = AgentRun::query()->lockForUpdate()->findOrFail($approval->agent_run_id);
            Gate::forUser($user)->authorize('resolveApprovals', $run);
            $approval = ToolApproval::query()->lockForUpdate()->findOrFail($approval->id);

            if ($approval->status !== 'pending') {
                return;
            }

            abort_unless($run->status === RunStatus::Succeeded, 409, 'Сначала дождитесь успешного завершения запуска.');
            abort_unless($approval->tool === 'propose_note', 422);

            if ($approve) {
                Note::query()->create([
                    'user_id' => $run->user_id,
                    'tool_approval_id' => $approval->id,
                    'title' => $approval->arguments['title'],
                    'body' => $approval->arguments['body'],
                ]);
            }

            $approval->update(['status' => $approve ? 'approved' : 'rejected', 'resolved_at' => now()]);
            $run->record('approval.'.$approval->status, ['approval_id' => $approval->id, 'tool' => $approval->tool]);
        });
    }
}
