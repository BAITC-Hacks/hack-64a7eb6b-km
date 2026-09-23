<?php

namespace Tests\Feature;

use App\Actions\AgentRuns\CreateRun;
use App\Ai\Agents\WorkspaceAgent;
use App\Enums\RunStatus;
use App\Jobs\ExecuteAgentRun;
use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class AgentRuntimeConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        WorkspaceAgent::fake()->preventStrayPrompts();
    }

    #[TestWith(['auto', null, 'demo', 'missing_key'])]
    #[TestWith(['auto', '', 'demo', 'missing_key'])]
    #[TestWith(['auto', " \t\n", 'demo', 'missing_key'])]
    #[TestWith(['auto', 'test-key', 'laravel', null])]
    #[TestWith(['demo', 'test-key', 'demo', 'forced_demo'])]
    #[TestWith(['laravel', null, 'laravel', null])]
    public function test_submission_snapshots_effective_mode_without_exposing_key(string $configured, ?string $key, string $effective, ?string $reason): void
    {
        config(['agents.driver' => $configured, 'ai.providers.openai.key' => $key]);
        $user = User::factory()->member()->create();
        $payload = ['input' => 'Вопрос', 'request_key' => (string) Str::uuid(), 'driver' => 'untrusted', 'context' => ['runtime' => ['driver' => 'untrusted']]];

        $this->actingAs($user)->post(route('runs.store'), $payload)->assertNoContent();
        $this->post(route('runs.store'), $payload)->assertNoContent();

        $run = AgentRun::query()->sole();
        self::assertSame($effective, $run->driver);
        self::assertSame($configured, $run->context['runtime']['configured_driver']);
        self::assertSame($effective, $run->context['runtime']['driver']);
        self::assertSame($reason, $run->context['runtime']['reason']);
        self::assertArrayNotHasKey('key', $run->context);
        self::assertStringNotContainsString('test-key', json_encode([$run->context, $run->events()->get()->toArray()]));
        Queue::assertPushed(ExecuteAgentRun::class, 1);
    }

    public function test_auto_uses_only_the_selected_providers_key(): void
    {
        config(['agents.driver' => 'auto', 'agents.provider' => 'anthropic', 'ai.providers.openai.key' => 'test-key', 'ai.providers.anthropic.key' => null]);

        $run = $this->createRun();

        self::assertSame('demo', $run->driver);
        self::assertSame('anthropic', $run->provider);
    }

    public function test_demo_snapshot_stays_offline_after_adding_key(): void
    {
        config(['agents.driver' => 'auto', 'ai.providers.openai.key' => null]);
        $run = $this->createRun();
        config(['ai.providers.openai.key' => 'test-key']);

        (new ExecuteAgentRun($run->id))->handle();

        self::assertSame(RunStatus::Succeeded, $run->fresh()->status);
        self::assertSame('demo', $run->fresh()->driver);
        self::assertSame(0, $run->fresh()->usage['prompt_tokens']);
        WorkspaceAgent::assertNeverPrompted();
        Http::assertNothingSent();
    }

    public function test_auto_live_response_uses_sdk_fake_and_snapshot_model(): void
    {
        config(['agents.driver' => 'auto', 'agents.model' => 'snapshot-model', 'ai.providers.openai.key' => 'test-key']);
        $run = $this->createRun();
        config(['agents.model' => 'changed-model']);
        WorkspaceAgent::fake(['Ответ AI'])->preventStrayPrompts();

        (new ExecuteAgentRun($run->id))->handle();

        self::assertSame(RunStatus::Succeeded, $run->fresh()->status);
        self::assertSame('Ответ AI', $run->fresh()->output);
        self::assertSame('snapshot-model', $run->fresh()->model);
        WorkspaceAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->model === 'snapshot-model');
    }

    #[TestWith(['auto', null])]
    #[TestWith(['demo', 'test-key'])]
    public function test_live_snapshot_fails_if_key_is_removed_or_live_is_disabled(string $driver, ?string $key): void
    {
        config(['agents.driver' => 'auto', 'ai.providers.openai.key' => 'test-key']);
        $run = $this->createRun();
        config(['agents.driver' => $driver, 'ai.providers.openai.key' => $key]);

        (new ExecuteAgentRun($run->id))->handle();

        self::assertSame(RunStatus::Failed, $run->fresh()->status);
        self::assertSame('laravel', $run->fresh()->driver);
        self::assertNull($run->fresh()->output);
        self::assertSame('demo', $this->createRun()->driver);
        WorkspaceAgent::assertNeverPrompted();
        Http::assertNothingSent();
    }

    public function test_strict_live_without_key_fails_without_prompting_even_when_faked(): void
    {
        config(['agents.driver' => 'laravel', 'ai.providers.openai.key' => null]);
        $run = $this->createRun();

        (new ExecuteAgentRun($run->id))->handle();

        self::assertSame(RunStatus::Failed, $run->fresh()->status);
        WorkspaceAgent::assertNeverPrompted();
    }

    public function test_provider_error_is_not_retried_or_replaced_by_demo(): void
    {
        config(['agents.driver' => 'auto', 'ai.providers.openai.key' => 'test-key']);
        $calls = 0;
        WorkspaceAgent::fake(function () use (&$calls): never {
            $calls++;
            throw new RuntimeException('secret-provider-error');
        })->preventStrayPrompts();
        $run = $this->createRun();

        (new ExecuteAgentRun($run->id))->handle();
        (new ExecuteAgentRun($run->id))->handle();

        self::assertSame(RunStatus::Failed, $run->fresh()->status);
        self::assertNull($run->fresh()->output);
        self::assertSame(0, $run->fresh()->tool_calls);
        self::assertStringNotContainsString('secret-provider-error', $run->fresh()->error);
        $this->assertDatabaseCount('tool_approvals', 0);
        self::assertSame(1, $calls);
    }

    #[TestWith(['auto', 0])]
    #[TestWith(['laravel', 1])]
    public function test_doctor_distinguishes_auto_demo_from_missing_required_key(string $driver, int $exitCode): void
    {
        config(['agents.driver' => $driver, 'ai.providers.openai.key' => null, 'queue.default' => 'database']);

        $this->artisan('app:doctor')->expectsOutputToContain('Configured runtime: '.$driver)
            ->expectsOutputToContain('No AI request was made.')->assertExitCode($exitCode);

        Http::assertNothingSent();
    }

    private function createRun(): AgentRun
    {
        return app(CreateRun::class)->handle(User::factory()->member()->create(), 'Вопрос', (string) Str::uuid());
    }
}
