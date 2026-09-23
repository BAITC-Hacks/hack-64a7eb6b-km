<?php

namespace Tests\Feature\Agents;

use App\Actions\AgentRuns\CreateRun;
use App\Actions\AgentRuns\StopRun;
use App\Ai\Agents\WorkspaceAgent;
use App\Ai\Runtime\DemoRuntime;
use App\Ai\Runtime\RunResult;
use App\Ai\ToolBroker;
use App\Enums\RunStatus;
use App\Jobs\ExecuteAgentRun;
use App\Models\AgentRun;
use App\Models\Note;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Tests\TestCase;

class AgentRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_guests_cannot_submit_runs(): void
    {
        $this->post(route('runs.store'), ['input' => 'hello', 'request_key' => (string) Str::uuid()])->assertRedirect(route('login'));
        $this->assertDatabaseCount('agent_runs', 0);
    }

    public function test_run_pages_are_removed_without_redirecting(): void
    {
        $run = $this->createRun();

        $this->actingAs($run->user)->get('/runs')->assertMethodNotAllowed();
        $this->get('/runs/'.$run->id)->assertNotFound();

        $this->assertModelExists($run);
    }

    public function test_submission_is_idempotent_and_configuration_is_selected_by_server(): void
    {
        $user = User::factory()->member()->create();
        $payload = ['input' => 'Plan a task', 'request_key' => (string) Str::uuid(), 'driver' => 'laravel', 'user_id' => 999, 'model' => 'untrusted'];
        $this->actingAs($user)->post(route('runs.store'), $payload)->assertNoContent()->assertSessionHasNoErrors();
        $this->post(route('runs.store'), $payload)->assertNoContent()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('agent_runs', 1);
        $run = AgentRun::query()->sole();
        self::assertSame($user->id, $run->user_id);
        self::assertSame('demo', $run->driver);
        self::assertSame(config('agents.model'), $run->model);
        self::assertSame(RunStatus::Queued, $run->status);
        Queue::assertPushed(ExecuteAgentRun::class, 1);
    }

    public function test_input_and_concurrency_are_bounded(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user)->post(route('runs.store'), ['input' => str_repeat('a', 6001), 'request_key' => 'invalid'])
            ->assertSessionHasErrors(['input', 'request_key']);
        for ($i = 0; $i < 3; $i++) {
            $this->createRun($user);
        }
        $this->post(route('runs.store'), ['input' => 'Fourth', 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('input');
        $this->assertDatabaseCount('agent_runs', 3);
    }

    public function test_other_users_cannot_read_cancel_or_approve_a_run(): void
    {
        $run = $this->createRun();
        (new ExecuteAgentRun($run->id))->handle();
        $approval = $run->approvals()->sole();
        $this->actingAs(User::factory()->member()->create());
        $this->get('/runs/'.$run->id)->assertNotFound();
        $this->post(route('runs.cancel', $run))->assertForbidden();
        $this->post(route('approvals.update', $approval), ['decision' => 'approve'])->assertForbidden();
    }

    public function test_demo_runs_real_tools_but_does_not_apply_a_write(): void
    {
        $run = $this->createRun();
        (new ExecuteAgentRun($run->id))->handle();
        $run->refresh();
        self::assertSame(RunStatus::Succeeded, $run->status);
        self::assertSame(2, $run->tool_calls);
        self::assertSame(0, $run->usage['prompt_tokens']);
        self::assertStringContainsString('Демо-режим', $run->output);
        $this->assertDatabaseCount('notes', 0);
        $this->assertDatabaseCount('tool_approvals', 1);
        self::assertSame('pending', $run->approvals()->sole()->status);
        self::assertTrue($run->events()->where('type', 'tool.completed')->count() === 2);

        (new ExecuteAgentRun($run->id))->handle();
        $this->assertDatabaseCount('tool_approvals', 1);
        self::assertSame(2, $run->fresh()->tool_calls);
    }

    public function test_approval_applies_exact_proposal_only_once(): void
    {
        $run = $this->createRun();
        (new ExecuteAgentRun($run->id))->handle();
        $approval = $run->approvals()->sole();
        $this->actingAs($run->user);
        $this->post(route('approvals.update', $approval), ['decision' => 'approve', 'arguments' => ['title' => 'tampered']])->assertRedirect();
        $this->post(route('approvals.update', $approval), ['decision' => 'approve'])->assertRedirect();

        $this->assertDatabaseCount('notes', 1);
        $note = Note::query()->sole();
        self::assertSame($approval->arguments['title'], $note->title);
        self::assertSame($approval->arguments['body'], $note->body);
        self::assertSame($run->user_id, $note->user_id);
        self::assertSame('approved', $approval->fresh()->status);
    }

    public function test_rejection_does_not_write_and_cannot_be_reversed(): void
    {
        $run = $this->createRun();
        (new ExecuteAgentRun($run->id))->handle();
        $approval = $run->approvals()->sole();
        $this->actingAs($run->user)->post(route('approvals.update', $approval), ['decision' => 'reject'])->assertRedirect();
        $this->post(route('approvals.update', $approval), ['decision' => 'approve'])->assertRedirect();
        $this->assertDatabaseCount('notes', 0);
        self::assertSame('rejected', $approval->fresh()->status);
    }

    public function test_queued_cancellation_prevents_execution(): void
    {
        $run = $this->createRun();
        $this->actingAs($run->user)->post(route('runs.cancel', $run))->assertRedirect();
        (new ExecuteAgentRun($run->id))->handle();
        self::assertSame(RunStatus::Cancelled, $run->fresh()->status);
        $this->assertDatabaseCount('tool_approvals', 0);
    }

    public function test_running_cancellation_rejects_proposals_and_discards_a_late_response(): void
    {
        $run = $this->createRun();
        $this->mock(DemoRuntime::class)->shouldReceive('execute')->once()->andReturnUsing(function (AgentRun $active): RunResult {
            app(ToolBroker::class)->execute($active, 'propose_note', ['title' => 'Pending', 'body' => 'No write']);
            app(StopRun::class)->handle($active->id);

            return new RunResult('Late response');
        });
        (new ExecuteAgentRun($run->id))->handle();
        self::assertSame(RunStatus::Cancelled, $run->fresh()->status);
        self::assertNull($run->fresh()->output);
        self::assertSame('rejected', $run->approvals()->sole()->status);
        $this->assertDatabaseCount('notes', 0);
    }

    public function test_tool_limit_fails_the_run_without_unbounded_calls(): void
    {
        $run = $this->createRun();
        $run->update(['limits' => [...$run->limits, 'max_tool_calls' => 1]]);
        (new ExecuteAgentRun($run->id))->handle();
        self::assertSame(RunStatus::Failed, $run->fresh()->status);
        self::assertSame(1, $run->fresh()->tool_calls);
        $this->assertDatabaseCount('tool_approvals', 0);
    }

    public function test_tools_only_search_the_run_owners_notes(): void
    {
        $run = $this->createRun();
        $run->update(['status' => RunStatus::Running]);
        $note = Note::query()->create(['user_id' => $run->user_id, 'title' => 'Mine', 'body' => 'Own data']);
        Note::query()->create(['user_id' => User::factory()->member()->create()->id, 'title' => 'Secret', 'body' => 'Other tenant']);
        $result = app(ToolBroker::class)->execute($run, 'search_notes', ['query' => '', 'user_id' => 999]);
        self::assertSame([$note->id], array_column($result['notes'], 'id'));
        self::assertTrue($result['content_is_untrusted']);
    }

    public function test_tools_refuse_cancelled_runs(): void
    {
        $run = $this->createRun();
        app(StopRun::class)->handle($run->id);
        $this->expectException(DomainException::class);
        app(ToolBroker::class)->execute($run, 'search_notes', ['query' => '']);
    }

    public function test_unknown_tools_cannot_execute(): void
    {
        $run = $this->createRun();
        $run->update(['status' => RunStatus::Running]);
        $this->expectException(DomainException::class);
        app(ToolBroker::class)->execute($run, 'shell', ['command' => 'whoami']);
    }

    public function test_running_proposals_cannot_be_approved_before_success(): void
    {
        $run = $this->createRun();
        $run->update(['status' => RunStatus::Running]);
        app(ToolBroker::class)->execute($run, 'propose_note', ['title' => 'Pending', 'body' => 'Draft']);
        $this->actingAs($run->user)->post(route('approvals.update', $run->approvals()->sole()), ['decision' => 'approve'])->assertStatus(409);
        $this->assertDatabaseCount('notes', 0);
    }

    public function test_laravel_ai_adapter_records_response_and_usage_using_sdk_fake(): void
    {
        config(['agents.driver' => 'laravel']);
        $run = $this->createRun();
        WorkspaceAgent::fake([
            new AgentResponse('fixture', 'A tested answer', new Usage(21, 9), new Meta('openai', $run->model)),
        ])->preventStrayPrompts();
        (new ExecuteAgentRun($run->id))->handle();

        self::assertSame(RunStatus::Succeeded, $run->fresh()->status);
        self::assertSame('A tested answer', $run->fresh()->output);
        self::assertSame(21, $run->fresh()->usage['prompt_tokens']);
        WorkspaceAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->prompt === $run->input && $prompt->agent->maxSteps() === 5);
    }

    public function test_test_environment_refuses_unfaked_ai_calls(): void
    {
        config(['agents.driver' => 'laravel']);
        $run = $this->createRun();
        (new ExecuteAgentRun($run->id))->handle();
        self::assertSame(RunStatus::Failed, $run->fresh()->status);
        self::assertNull($run->fresh()->output);
        self::assertStringNotContainsString('key', $run->fresh()->error);
    }

    public function test_abandoned_jobs_are_failed_and_pending_writes_rejected(): void
    {
        $run = $this->createRun();
        $run->update(['status' => RunStatus::Running, 'started_at' => now()->subMinutes(6)]);
        app(ToolBroker::class)->execute($run, 'propose_note', ['title' => 'Pending', 'body' => 'Draft']);
        $queued = $this->createRun();
        $queued->update(['created_at' => now()->subHours(2)]);
        $this->artisan('agents:expire-runs')->assertSuccessful();
        self::assertSame(RunStatus::Failed, $run->fresh()->status);
        self::assertSame(RunStatus::Failed, $queued->fresh()->status);
        self::assertSame('rejected', $run->approvals()->sole()->status);
    }

    public function test_filament_is_admin_only_and_read_only(): void
    {
        $run = $this->createRun();
        $this->actingAs($run->user)->get('/admin')->assertForbidden();
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin)->get('/admin/agent-runs')->assertOk();
        $this->get('/admin/agent-runs/'.$run->id)->assertOk();
        $this->get('/admin/agent-runs/'.$run->id.'/edit')->assertNotFound();
        $this->get('/runs/'.$run->id)->assertNotFound();
    }

    private function createRun(?User $user = null): AgentRun
    {
        return app(CreateRun::class)->handle($user ?? User::factory()->member()->create(), 'Помоги спланировать работу', (string) Str::uuid());
    }
}
