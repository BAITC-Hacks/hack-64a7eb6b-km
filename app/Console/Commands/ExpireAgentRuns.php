<?php

namespace App\Console\Commands;

use App\Actions\AgentRuns\StopRun;
use App\Enums\RunStatus;
use App\Models\AgentRun;
use Illuminate\Console\Command;

class ExpireAgentRuns extends Command
{
    protected $signature = 'agents:expire-runs';

    protected $description = 'Mark abandoned queued and running tasks as failed';

    public function handle(StopRun $stop): int
    {
        $runs = AgentRun::query()->where(function ($query) {
            $query->where(fn ($running) => $running->where('status', RunStatus::Running)->where('started_at', '<', now()->subMinutes(5)))
                ->orWhere(fn ($queued) => $queued->where('status', RunStatus::Queued)->where('created_at', '<', now()->subHour()));
        })->cursor();

        foreach ($runs as $run) {
            $stop->handle($run->id, RunStatus::Failed, 'Запуск превысил время ожидания. Проверьте worker и создайте новую задачу.');
        }

        return self::SUCCESS;
    }
}
