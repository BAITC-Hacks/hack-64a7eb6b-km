<?php

namespace Tests\Feature;

use App\Actions\AgentRuns\CreateScenarioRun;
use App\Actions\AgentRuns\StopRun;
use App\Ai\Agents\ScenarioAnalysisAgent;
use App\Ai\Agents\ScenarioChatAgent;
use App\Ai\Agents\WorkspaceAgent;
use App\Ai\Runtime\DemoRuntime;
use App\Ai\Runtime\RunResult;
use App\Ai\ToolBroker;
use App\Enums\RunStatus;
use App\Jobs\ExecuteAgentRun;
use App\Models\AgentRun;
use App\Models\SimulationScenario;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ScenarioAiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        WorkspaceAgent::fake()->preventStrayPrompts();
        ScenarioAnalysisAgent::fake()->preventStrayPrompts();
        ScenarioChatAgent::fake()->preventStrayPrompts();
    }

    public function test_submission_snapshots_facts_and_configuration_and_rejects_duplicate_active_chat(): void
    {
        $scenario = SimulationScenario::factory()->create();
        $payload = ['input' => 'Что улучшить?', 'request_key' => (string) Str::uuid(), 'model' => 'untrusted', 'context' => ['score' => 100], 'user_id' => 99];
        $this->actingAs($scenario->user)->post(route('scenarios.messages', $scenario), $payload)->assertRedirect();
        $this->post(route('scenarios.messages', $scenario), $payload)->assertRedirect();
        $run = AgentRun::query()->sole();
        self::assertSame($scenario->user_id, $run->user_id);
        self::assertSame(config('agents.model'), $run->model);
        self::assertSame('scenario-chat-v1', $run->prompt_version);
        self::assertEquals($scenario->result, $run->context['facts']['result']);
        Queue::assertPushed(ExecuteAgentRun::class, 1);
        $this->postJson(route('scenarios.messages', $scenario), [...$payload, 'request_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('input');
        $this->postJson(route('scenarios.messages', $scenario), [...$payload, 'input' => 'Другое'])->assertConflict();
        $this->get('/runs/'.$run->id)->assertNotFound();
    }

    public function test_analysis_uses_structured_fake_and_preserves_exact_calculation(): void
    {
        config(['agents.driver' => 'laravel', 'ai.providers.openai.key' => 'test-key']);
        $run = $this->runScenario('scenario_analysis');
        $report = ['summary' => 'Приоритет — Нура.', 'strengths' => ['Социальные меры'], 'risks' => ['Транспорт'], 'tradeoffs' => ['Ограниченный бюджет'], 'recommendations' => [['alternative_id' => $run->scenario->alternatives[0]['id'], 'reason' => 'Расчёт подтверждает улучшение.']]];
        ScenarioAnalysisAgent::fake([new StructuredAgentResponse('fixture', $report, '', new Usage(120, 80), new Meta('openai', $run->model))])->preventStrayPrompts();
        (new ExecuteAgentRun($run->id))->handle();
        $run->refresh();
        self::assertSame(RunStatus::Succeeded, $run->status);
        self::assertEquals($report, $run->output_data);
        self::assertSame(120, $run->usage['prompt_tokens']);
        self::assertSame('56.54307000', $run->scenario->result['score']);
        ScenarioAnalysisAgent::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '56.54307000'));
        $this->actingAs($run->user)->get(route('scenarios.show', $run->scenario))->assertInertia(fn (Assert $page) => $page->where('runs.0.output_data.summary', $report['summary'])->missing('runs.0.context'));
        $this->get(route('map', ['scenario' => $run->simulation_scenario_id]))->assertInertia(fn (Assert $page) => $page
            ->component('welcome')->where('runs.0.output_data.summary', $report['summary'])->missing('runs.0.context'));
    }

    #[TestWith(['scenarios.analysis', 'scenario_analysis'])]
    #[TestWith(['scenarios.messages', 'scenario_chat'])]
    public function test_map_submissions_queue_the_saved_scenario_and_return_to_its_map(string $route, string $kind): void
    {
        $scenario = SimulationScenario::factory()->create();

        $this->actingAs($scenario->user)->post(route($route, $scenario), [
            'input' => 'Объясни мой сценарий', 'request_key' => (string) Str::uuid(), 'return_to' => 'map',
        ])->assertRedirect(route('map', ['scenario' => $scenario->id]));

        $run = AgentRun::query()->sole();
        self::assertSame($kind, $run->kind);
        self::assertSame($scenario->id, $run->simulation_scenario_id);
        self::assertSame($scenario->user_id, $run->user_id);
        Queue::assertPushed(ExecuteAgentRun::class, 1);
    }

    public function test_map_history_and_proposals_belong_only_to_the_selected_scenario(): void
    {
        $run = $this->runScenario();
        $run->update(['status' => RunStatus::Running]);
        app(ToolBroker::class)->execute($run, 'propose_scenario', ['selections' => $run->scenario->alternatives[0]['selections']]);
        $run->update(['status' => RunStatus::Succeeded, 'output' => 'Сохранённый ответ']);
        $other = $this->runScenario('scenario_chat', SimulationScenario::factory()->for($run->user)->create());
        $other->update(['status' => RunStatus::Succeeded, 'output' => 'Ответ другого сценария']);

        $this->actingAs($run->user)->get(route('map', ['scenario' => $run->simulation_scenario_id]))
            ->assertInertia(fn (Assert $page) => $page->has('runs', 1)
                ->where('runs.0.id', $run->id)->where('runs.0.output', 'Сохранённый ответ')
                ->has('approvals', 1)->where('approvals.0.id', $run->approvals()->sole()->id)
                ->missing('runs.0.context')->missing('runs.0.user_id'));
    }

    public function test_invalid_analysis_does_not_damage_scenario_or_expose_provider_response(): void
    {
        config(['agents.driver' => 'laravel', 'ai.providers.openai.key' => 'test-key']);
        $run = $this->runScenario('scenario_analysis');
        ScenarioAnalysisAgent::fake([new StructuredAgentResponse('fixture', ['summary' => 'secret-provider-response'], '', new Usage(1, 1), new Meta('openai', $run->model))])->preventStrayPrompts();
        (new ExecuteAgentRun($run->id))->handle();
        self::assertSame(RunStatus::Failed, $run->fresh()->status);
        self::assertNull($run->fresh()->output_data);
        self::assertStringNotContainsString('secret-provider-response', $run->fresh()->error);
        self::assertSame(1, $run->fresh()->usage['completion_tokens']);
        self::assertSame(['strengths', 'risks', 'tradeoffs', 'recommendations'], $run->events()->where('type', 'analysis.invalid_response')->sole()->data['fields']);
        self::assertSame('56.54307000', $run->scenario->result['score']);
    }

    public function test_chat_history_is_bounded_ordered_and_scoped_to_scenario(): void
    {
        config(['agents.driver' => 'laravel', 'ai.providers.openai.key' => 'test-key']);
        $scenario = SimulationScenario::factory()->create();
        for ($i = 0; $i < 7; $i++) {
            $previous = $this->runScenario('scenario_chat', $scenario, 'Вопрос '.$i);
            $previous->update(['status' => RunStatus::Succeeded, 'output' => 'Ответ '.$i, 'created_at' => now()->subMinutes(10 - $i)]);
        }
        $other = $this->runScenario('scenario_chat', SimulationScenario::factory()->for($scenario->user)->create(), 'Чужой контекст');
        $other->update(['status' => RunStatus::Succeeded, 'output' => 'Не включать']);
        $current = $this->runScenario('scenario_chat', $scenario);
        self::assertCount(12, $current->context['messages']);
        self::assertSame('Вопрос 1', $current->context['messages'][0]['content']);
        self::assertSame('Ответ 6', $current->context['messages'][11]['content']);
        ScenarioChatAgent::fake([new AgentResponse('fixture', 'Объяснение', new Usage(50, 10), new Meta('openai', $current->model))])->preventStrayPrompts();
        (new ExecuteAgentRun($current->id))->handle();
        self::assertSame(RunStatus::Succeeded, $current->fresh()->status);
        ScenarioChatAgent::assertPrompted(fn (AgentPrompt $prompt) => count(iterator_to_array($prompt->agent->messages())) === 12);
    }

    public function test_tools_evaluate_validate_and_charge_invalid_calls_without_saving(): void
    {
        $run = $this->runScenario();
        $run->update(['status' => RunStatus::Running]);
        $broker = app(ToolBroker::class);
        $result = $broker->execute($run, 'evaluate_scenario', ['selections' => $run->scenario->selections]);
        self::assertTrue($result['valid']);
        self::assertSame('56.54307000', $result['result']['score']);
        self::assertFalse($broker->execute($run, 'propose_scenario', ['selections' => []])['valid']);
        self::assertFalse($broker->execute($run, 'evaluate_scenario', ['selections' => $run->scenario->selections, 'user_id' => 999])['valid']);
        self::assertSame(3, $run->fresh()->tool_calls);
        $this->assertDatabaseCount('simulation_scenarios', 1);
        $this->assertDatabaseCount('tool_approvals', 0);
        $run->update(['limits' => [...$run->limits, 'max_tool_calls' => 3]]);
        $this->expectException(DomainException::class);
        $broker->execute($run, 'evaluate_scenario', ['selections' => $run->scenario->selections]);
    }

    public function test_proposal_is_deduplicated_and_only_owner_approval_creates_exact_variant_once(): void
    {
        $run = $this->runScenario();
        $run->update(['status' => RunStatus::Running]);
        $selections = $run->scenario->alternatives[0]['selections'];
        $broker = app(ToolBroker::class);
        $first = $broker->execute($run, 'propose_scenario', ['selections' => $selections]);
        $second = $broker->execute($run, 'propose_scenario', ['selections' => array_reverse($selections)]);
        self::assertSame($first['approval_id'], $second['approval_id']);
        $approval = $run->approvals()->sole();
        $this->assertDatabaseCount('simulation_scenarios', 1);
        $this->actingAs($run->user)->post(route('approvals.update', $approval), ['decision' => 'approve'])->assertConflict();
        $run->update(['status' => RunStatus::Succeeded]);
        $this->actingAs(User::factory()->administrator()->create())->post(route('approvals.update', $approval), ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($run->user)->post(route('approvals.update', $approval), ['decision' => 'approve', 'arguments' => ['result' => ['score' => 100]]])->assertRedirect();
        $this->post(route('approvals.update', $approval), ['decision' => 'approve'])->assertRedirect();
        $this->assertDatabaseCount('simulation_scenarios', 2);
        $variant = SimulationScenario::query()->where('tool_approval_id', $approval->id)->sole();
        self::assertEquals($approval->arguments['result'], $variant->result);
        self::assertEquals($selections, $variant->selections);
        self::assertSame($run->user_id, $variant->user_id);
        self::assertSame($run->simulation_scenario_id, $variant->source_scenario_id);
        self::assertSame('approved', $approval->fresh()->status);
    }

    public function test_cancellation_rejects_proposal_and_discards_late_structured_output(): void
    {
        $run = $this->runScenario();
        $this->mock(DemoRuntime::class)->shouldReceive('execute')->once()->andReturnUsing(function (AgentRun $active): RunResult {
            app(ToolBroker::class)->execute($active, 'propose_scenario', ['selections' => $active->scenario->selections]);
            app(StopRun::class)->handle($active->id);

            return new RunResult('Поздний ответ', [], ['summary' => 'Поздний разбор']);
        });
        (new ExecuteAgentRun($run->id))->handle();
        self::assertSame(RunStatus::Cancelled, $run->fresh()->status);
        self::assertNull($run->fresh()->output_data);
        self::assertSame('rejected', $run->approvals()->sole()->status);
        $this->assertDatabaseCount('simulation_scenarios', 1);
    }

    public function test_foreign_scenario_and_read_only_user_cannot_start_ai(): void
    {
        $scenario = SimulationScenario::factory()->create();
        $this->actingAs(User::factory()->member()->create())->post(route('scenarios.analysis', $scenario), ['request_key' => (string) Str::uuid()])->assertForbidden();
        $observer = User::factory()->observer()->create();
        $own = SimulationScenario::factory()->for($observer)->create();
        $this->actingAs($observer)->post(route('scenarios.messages', $own), ['input' => 'hello', 'request_key' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('agent_runs', 0);
    }

    public function test_admin_scenario_view_is_read_only_and_does_not_bypass_frontend_ownership(): void
    {
        $scenario = SimulationScenario::factory()->create();
        $this->actingAs($scenario->user)->get('/admin/simulation-scenarios')->assertForbidden();
        $this->actingAs(User::factory()->administrator()->create())->get('/admin/simulation-scenarios')->assertOk();
        $this->get('/admin/simulation-scenarios/'.$scenario->id)->assertOk();
        $this->get('/admin/simulation-scenarios/'.$scenario->id.'/edit')->assertNotFound();
        $this->get('/admin/simulation-scenarios/create')->assertNotFound();
        $this->get(route('scenarios.show', $scenario))->assertForbidden();
    }

    private function runScenario(string $kind = 'scenario_chat', ?SimulationScenario $scenario = null, string $input = 'Объясни результат'): AgentRun
    {
        $scenario ??= SimulationScenario::factory()->create();

        return app(CreateScenarioRun::class)->handle($scenario->user, $scenario, $kind, $input, (string) Str::uuid());
    }
}
