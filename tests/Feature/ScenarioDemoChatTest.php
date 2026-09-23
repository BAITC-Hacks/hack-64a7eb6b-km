<?php

namespace Tests\Feature;

use App\Actions\AgentRuns\CreateScenarioRun;
use App\Ai\Agents\ScenarioAnalysisAgent;
use App\Ai\Agents\ScenarioChatAgent;
use App\Enums\RunStatus;
use App\Jobs\ExecuteAgentRun;
use App\Models\AgentRun;
use App\Models\SimulationScenario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ScenarioDemoChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agents.driver' => 'auto', 'ai.providers.openai.key' => null]);
        Queue::fake();
        ScenarioAnalysisAgent::fake()->preventStrayPrompts();
        ScenarioChatAgent::fake()->preventStrayPrompts();
    }

    #[TestWith(['Как распределён бюджет?', '95 из 100', 'Остаток: 5'])]
    #[TestWith(['Почему изменилась оценка?', '52,56 → 56,54', '70%'])]
    #[TestWith(['Расскажи о Нуре', 'Нура: индекс', 'Разгрузка дорог'])]
    #[TestWith(['Какие риски остаются?', 'Самый слабый район', 'ниже 40'])]
    #[TestWith(['Когда появится эффект?', '8 кварталов', 'лаг'])]
    #[TestWith(['Какие синергии сработали?', 'Сработавшие синергии', 'уже включены'])]
    #[TestWith(['Покажи варианты улучшения', 'Вариант 1:', 'Вариант 2:'])]
    #[TestWith(['Напиши стих о Луне', 'Могу объяснить бюджет', 'Свободные ответы'])]
    public function test_demo_answers_from_scenario_facts_without_creating_proposals(string $question, string $first, string $second): void
    {
        $scenario = SimulationScenario::factory()->create();

        $run = $this->chat($scenario, $question);

        self::assertStringContainsString($first, $run->output);
        self::assertStringContainsString($second, $run->output);
        self::assertSame(0, $run->usage['prompt_tokens']);
        $this->assertDatabaseCount('tool_approvals', 0);
        ScenarioChatAgent::assertNeverPrompted();
        Http::assertNothingSent();
    }

    public function test_follow_up_remembers_district_across_intervening_question(): void
    {
        $scenario = SimulationScenario::factory()->create();
        $this->chat($scenario, 'Расскажи о Нуре');
        $this->chat($scenario, 'Какой бюджет?');

        $run = $this->chat($scenario, 'Какие в нём риски?');

        self::assertSame('nura', $run->context['dialogue']['district_id']);
        self::assertStringContainsString('Нура: индекс', $run->output);
    }

    public function test_context_expires_after_six_pairs_and_is_scoped_to_scenario(): void
    {
        $scenario = SimulationScenario::factory()->create();
        $this->chat($scenario, 'Расскажи о Нуре');
        $other = SimulationScenario::factory()->for($scenario->user)->create();
        self::assertStringContainsString('Уточните район', $this->chat($other, 'Какие в нём риски?')->output);
        for ($index = 0; $index < 6; $index++) {
            $this->chat($scenario, 'Какой бюджет?');
        }

        $run = $this->chat($scenario, 'Какие в нём риски?');

        self::assertStringContainsString('Уточните район', $run->output);
        self::assertCount(12, $run->context['messages']);
    }

    public function test_second_variant_is_evaluated_then_prepared_and_created_only_after_approval(): void
    {
        $scenario = SimulationScenario::factory()->create();
        $this->chat($scenario, 'Покажи варианты улучшения');
        $comparison = $this->chat($scenario, 'Сравни второй вариант');
        self::assertSame(1, $comparison->tool_calls);
        self::assertStringContainsString('Вариант 2:', $comparison->output);

        $run = $this->chat($scenario, 'Подготовь его');

        self::assertSame(2, $run->tool_calls);
        self::assertSame(['evaluate_scenario', 'propose_scenario'], $run->events()->where('type', 'tool.started')->orderBy('id')->get()->pluck('data.tool')->all());
        $approval = $run->approvals()->sole();
        self::assertEquals($scenario->alternatives[1]['selections'], $approval->arguments['selections']);
        $this->assertDatabaseCount('simulation_scenarios', 1);
        $this->actingAs($scenario->user)->post(route('approvals.update', $approval), ['decision' => 'approve'])->assertRedirect();
        $this->post(route('approvals.update', $approval), ['decision' => 'approve'])->assertRedirect();
        $this->assertDatabaseCount('simulation_scenarios', 2);
        $variant = SimulationScenario::query()->where('tool_approval_id', $approval->id)->sole();
        self::assertEquals($approval->arguments['result'], $variant->result);
        self::assertSame('approved', $approval->fresh()->status);
    }

    #[TestWith(['Подготовь его', 'Какой вариант'])]
    #[TestWith(['Подготовь вариант 99', 'Такого варианта нет'])]
    #[TestWith(['Подготовь первый или второй вариант', 'Уточните один номер'])]
    #[TestWith(['Не сохраняй второй вариант, только сравни', 'Вариант 2:'])]
    #[TestWith(['Расскажи о Нуре и Есиле', 'Есиль: индекс'])]
    public function test_ambiguous_invalid_or_negated_requests_do_not_prepare_proposals(string $question, string $expected): void
    {
        $scenario = SimulationScenario::factory()->create();

        $run = $this->chat($scenario, $question);

        self::assertStringContainsString($expected, $run->output);
        $this->assertDatabaseCount('tool_approvals', 0);
    }

    public function test_absence_of_improvements_is_not_claimed_as_global_optimum(): void
    {
        $scenario = SimulationScenario::factory()->create(['alternatives' => []]);

        $run = $this->chat($scenario, 'Подготовь лучший вариант');

        self::assertStringContainsString('Улучшающих замен одной меры не найдено', $run->output);
        self::assertStringContainsString('не доказывает глобальную оптимальность', $run->output);
        $this->assertDatabaseCount('tool_approvals', 0);
    }

    public function test_changing_topic_to_a_district_does_not_prepare_an_old_variant(): void
    {
        $scenario = SimulationScenario::factory()->create();
        $this->chat($scenario, 'Сравни второй вариант');
        $this->chat($scenario, 'Расскажи о Нуре');

        $run = $this->chat($scenario, 'Подготовь его');

        self::assertStringContainsString('Какой вариант', $run->output);
        $this->assertDatabaseCount('tool_approvals', 0);
    }

    public function test_tool_budget_prevents_preparation_after_evaluation(): void
    {
        config(['agents.max_tool_calls' => 1]);
        $scenario = SimulationScenario::factory()->create();
        $run = app(CreateScenarioRun::class)->handle($scenario->user, $scenario, 'scenario_chat', 'Подготовь лучший вариант', (string) Str::uuid());

        (new ExecuteAgentRun($run->id))->handle();

        self::assertSame(RunStatus::Failed, $run->fresh()->status);
        self::assertSame(1, $run->fresh()->tool_calls);
        $this->assertDatabaseCount('tool_approvals', 0);
    }

    #[TestWith(['map'])]
    #[TestWith(['scenarios.show'])]
    public function test_pages_expose_mode_and_safe_activity_without_internal_context(string $route): void
    {
        $scenario = SimulationScenario::factory()->create();
        $run = $this->chat($scenario, 'Подготовь лучший вариант');
        $run->record('tool.completed', ['tool' => 'evaluate_scenario', 'evaluation' => ['secret' => 'private-data'], 'key' => 'private-key']);
        config(['ai.providers.openai.key' => 'private-key']);

        $this->actingAs($scenario->user)->get(route($route, ['scenario' => $scenario->id]))->assertInertia(fn (Assert $page) => $page
            ->where('aiRuntime.driver', 'laravel')
            ->where('runs.0.driver', 'demo')
            ->where('runs.0.output_data', null)
            ->has('runs.0.activity', 6)
            ->where('runs.0.activity.0.tool', 'evaluate_scenario')
            ->missing('runs.0.context')->missing('runs.0.activity.5.evaluation')
            ->missing('runs.0.activity.5.key')->missing('aiRuntime.key'));
    }

    public function test_demo_analysis_preserves_structured_report_and_uses_no_provider(): void
    {
        $scenario = SimulationScenario::factory()->create();
        $run = app(CreateScenarioRun::class)->handle($scenario->user, $scenario, 'scenario_analysis', 'Разбор', (string) Str::uuid());

        (new ExecuteAgentRun($run->id))->handle();

        self::assertSame(RunStatus::Succeeded, $run->fresh()->status);
        self::assertStringContainsString('56,54', $run->fresh()->output_data['summary']);
        self::assertCount(3, $run->fresh()->output_data['recommendations']);
        ScenarioAnalysisAgent::assertNeverPrompted();
    }

    public function test_failed_chat_can_be_submitted_again_only_with_new_request_key(): void
    {
        $scenario = SimulationScenario::factory()->create();
        $key = (string) Str::uuid();
        $payload = ['input' => 'Какой бюджет?', 'request_key' => $key];
        $this->actingAs($scenario->user)->post(route('scenarios.messages', $scenario), $payload)->assertRedirect();
        AgentRun::query()->sole()->update(['status' => RunStatus::Failed]);

        $this->post(route('scenarios.messages', $scenario), $payload)->assertRedirect();
        $this->assertDatabaseCount('agent_runs', 1);
        $this->post(route('scenarios.messages', $scenario), [...$payload, 'request_key' => (string) Str::uuid()])->assertRedirect();

        $this->assertDatabaseCount('agent_runs', 2);
        Queue::assertPushed(ExecuteAgentRun::class, 2);
    }

    private function chat(SimulationScenario $scenario, string $input): AgentRun
    {
        $run = app(CreateScenarioRun::class)->handle($scenario->user, $scenario, 'scenario_chat', $input, (string) Str::uuid());
        (new ExecuteAgentRun($run->id))->handle();
        $run->refresh();
        self::assertSame(RunStatus::Succeeded, $run->status, $run->error ?? '');

        return $run;
    }
}
