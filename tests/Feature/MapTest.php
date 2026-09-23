<?php

namespace Tests\Feature;

use App\Models\SimulationScenario;
use App\Models\User;
use Database\Seeders\SimulationDatasetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MapTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_shows_the_dashboard_baseline_without_example_decisions(): void
    {
        $this->seed(SimulationDatasetSeeder::class);
        $user = User::factory()->member()->create();

        $response = $this->actingAs($user)->get(route('map'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('dataset.version', 'astana-v1')
            ->where('baseline.score', '52.55768000')
            ->where('baseline.cost', 0)
            ->where('scenario', null)
            ->where('recentScenarios', [])
            ->where('runs', [])
            ->where('approvals', [])
            ->where('can.create', true)
            ->where('can.analyze', false));
        $this->assertDatabaseCount('simulation_scenarios', 0);
        $this->assertDatabaseCount('agent_runs', 0);
    }

    public function test_map_handles_an_unprepared_dataset_without_fabricated_results(): void
    {
        $response = $this->actingAs(User::factory()->member()->create())->get(route('map'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('welcome')->where('dataset', null)->where('baseline', null)
            ->where('scenario', null)->where('can.create', false));
    }

    public function test_map_selects_only_the_owners_latest_scenario_and_its_dataset_snapshot(): void
    {
        $user = User::factory()->member()->create();
        $earlier = SimulationScenario::factory()->for($user)->create(['created_at' => '2026-01-01 10:00:00']);
        $latest = SimulationScenario::factory()->for($user)->create(['title' => 'Сохранённый вариант', 'created_at' => '2026-01-02 10:00:00']);
        SimulationScenario::factory()->create(['title' => 'Чужой сценарий', 'created_at' => '2026-01-03 10:00:00']);

        $response = $this->actingAs($user)->get(route('map'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('scenario.id', $latest->id)
            ->where('scenario.title', 'Сохранённый вариант')
            ->where('scenario.result.score', '56.54307000')
            ->where('scenario.result.cost', 95)
            ->where('scenario.selections', $latest->selections)
            ->where('dataset.version', $latest->dataset->version)
            ->has('recentScenarios', 2)
            ->where('recentScenarios.0.id', $latest->id)
            ->where('recentScenarios.1.id', $earlier->id)
            ->missing('scenario.user_id')->missing('scenario.request_key'));
    }

    public function test_map_can_open_an_older_selected_scenario(): void
    {
        $user = User::factory()->member()->create();
        $selected = SimulationScenario::factory()->for($user)->create(['created_at' => '2026-01-01 10:00:00']);
        SimulationScenario::factory()->count(12)->for($user)->for($selected->dataset, 'dataset')->create(['created_at' => '2026-01-02 10:00:00']);

        $response = $this->actingAs($user)->get(route('map', ['scenario' => $selected->id]));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('scenario.id', $selected->id)
            ->where('recentScenarios.0.id', $selected->id)
            ->where('dataset.version', $selected->dataset->version));
    }

    #[TestWith(['member'])]
    #[TestWith(['administrator'])]
    public function test_map_does_not_expose_another_users_scenario(string $role): void
    {
        $scenario = SimulationScenario::factory()->create();
        $user = User::factory()->{$role}()->create();

        $response = $this->actingAs($user)->get(route('map', ['scenario' => $scenario->id]));

        $response->assertNotFound();
    }

    public function test_map_requires_verified_workspace_access(): void
    {
        $user = User::factory()->member()->unverified()->create();
        $this->actingAs($user)->get(route('map'))->assertRedirect(route('verification.notice'));

        $this->actingAs(User::factory()->create())->get(route('map'))
            ->assertInertia(fn (Assert $page) => $page->component('access-denied')->missing('scenario')->missing('runs'));
    }

    public function test_map_allows_observers_to_read_without_write_controls(): void
    {
        $user = User::factory()->observer()->create();
        $scenario = SimulationScenario::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(route('map'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('scenario.id', $scenario->id)
            ->where('can', ['create' => false, 'analyze' => false, 'cancel' => false, 'approve' => false]));
    }

    public function test_map_rejects_invalid_scenario_identifiers(): void
    {
        $this->actingAs(User::factory()->member()->create())
            ->getJson(route('map', ['scenario' => 'invalid']))
            ->assertUnprocessable()->assertJsonValidationErrors('scenario');
    }

    public function test_map_polling_refreshes_permissions_and_stays_on_the_selected_scenario(): void
    {
        $user = User::factory()->member()->create();
        $scenario = SimulationScenario::factory()->for($user)->create();
        $this->actingAs($user)->get(route('map', ['scenario' => $scenario->id]))
            ->assertInertia(fn (Assert $page) => $page->where('can.analyze', true));
        $user->syncRoles(['Аналитик']);

        $response = $this->actingAs($user->fresh())->get(route('map', ['scenario' => $scenario->id]), [
            'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'welcome', 'X-Inertia-Partial-Data' => 'runs,approvals',
            'X-Inertia-Version' => Inertia::getVersion(),
        ]);

        $response->assertOk()->assertJsonPath('props.runs', [])->assertJsonPath('props.approvals', [])
            ->assertJsonPath('props.can.analyze', false)->assertJsonMissingPath('props.dataset');
    }
}
