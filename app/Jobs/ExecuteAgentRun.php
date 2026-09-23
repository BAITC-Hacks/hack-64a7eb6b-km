<?php

namespace App\Jobs;

use App\Actions\AgentRuns\StopRun;
use App\Ai\Runtime\DemoRuntime;
use App\Ai\Runtime\LaravelAiRuntime;
use App\Enums\RunStatus;
use App\Models\AgentRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteAgentRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(public string $runId)
    {
        $this->onQueue(config('agents.queue'));
    }

    public function handle(): void
    {
        $run = DB::transaction(function (): ?AgentRun {
            $run = AgentRun::query()->lockForUpdate()->find($this->runId);
            if (! $run || $run->status !== RunStatus::Queued) {
                return null;
            }
            $run->update(['status' => RunStatus::Running, 'started_at' => now()]);
            $run->record('run.started');

            return $run;
        });
        if (! $run) {
            return;
        }

        try {
            $runtime = match ($run->driver) {
                'demo' => app(DemoRuntime::class),
                'laravel' => app(LaravelAiRuntime::class),
                default => throw new \LogicException('Unknown runtime driver.'),
            };
            $result = $runtime->execute($run);

            DB::transaction(function () use ($result): void {
                $run = AgentRun::query()->lockForUpdate()->find($this->runId);
                if (! $run || $run->status !== RunStatus::Running) {
                    return; // Cancellation always wins over an in-flight provider response.
                }
                $run->update(['status' => RunStatus::Succeeded, 'output' => $result->text, 'output_data' => $result->data, 'usage' => $result->usage, 'finished_at' => now()]);
                $run->record('run.succeeded', ['usage' => $result->usage]);
            });
        } catch (Throwable $exception) {
            $this->failed($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        // Provider exceptions can contain request bodies or credentials. Record only the class.
        Log::warning('Agent run failed', ['run_id' => $this->runId, 'exception_class' => $exception ? $exception::class : null]);
        app(StopRun::class)->handle($this->runId, RunStatus::Failed, 'Не удалось завершить запуск. Проверьте настройки провайдера, лимиты и состояние worker.');
    }
}
