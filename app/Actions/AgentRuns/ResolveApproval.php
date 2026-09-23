<?php

namespace App\Actions\AgentRuns;

use App\Actions\Simulations\CalculateScenario;
use App\Actions\Simulations\CreateScenario;
use App\Enums\RunStatus;
use App\Models\AgentRun;
use App\Models\Note;
use App\Models\SimulationScenario;
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
            abort_unless(in_array($approval->tool, ['propose_note', 'propose_scenario'], true), 422);

            if ($approve && $approval->tool === 'propose_note') {
                Note::query()->create([
                    'user_id' => $run->user_id,
                    'tool_approval_id' => $approval->id,
                    'title' => $approval->arguments['title'],
                    'body' => $approval->arguments['body'],
                ]);
            }

            if ($approve && $approval->tool === 'propose_scenario') {
                $source = SimulationScenario::query()->findOrFail($run->simulation_scenario_id);
                Gate::forUser($user)->authorize('view', $source);
                Gate::forUser($user)->authorize('create', SimulationScenario::class);
                $payload = $approval->arguments;
                abort_unless($source->id === $payload['source_scenario_id'] && $source->simulation_dataset_id === $payload['dataset_id'] && $source->calculator_version === $payload['calculator_version'], 422);
                $result = app(CalculateScenario::class)->handle($source->dataset->data, $payload['selections'], $payload['calculator_version']);
                abort_unless($result == $payload['result'], 422, 'Расчёт предложения больше не воспроизводится.');
                $created = app(CreateScenario::class)->handle($user, $source->dataset, $payload['selections'], $payload['title'], null, $source, $approval->id);
                $run->record('scenario.created', ['scenario_id' => $created->id, 'approval_id' => $approval->id]);
            }

            $approval->update(['status' => $approve ? 'approved' : 'rejected', 'resolved_at' => now()]);
            $run->record('approval.'.$approval->status, ['approval_id' => $approval->id, 'tool' => $approval->tool]);
        });
    }
}
