<?php

namespace App\Actions\AgentRuns;

use App\Enums\RunStatus;
use App\Models\AgentRun;
use Illuminate\Support\Facades\DB;

class StopRun
{
    public function handle(string $id, RunStatus $status = RunStatus::Cancelled, ?string $error = null): void
    {
        DB::transaction(function () use ($id, $status, $error): void {
            $run = AgentRun::query()->lockForUpdate()->find($id);
            if (! $run || ! $run->status->isActive()) {
                return;
            }

            $run->update(['status' => $status, 'error' => $error, 'finished_at' => now()]);
            $run->approvals()->where('status', 'pending')->update(['status' => 'rejected', 'resolved_at' => now()]);
            $run->record('run.'.$status->value, ['message' => $error]);
        });
    }
}
