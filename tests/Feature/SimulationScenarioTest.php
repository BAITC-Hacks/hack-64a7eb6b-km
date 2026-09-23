<?php

namespace Tests\Feature;

use App\Actions\Simulations\CalculateScenario;
use App\Actions\Simulations\FindScenarioAlternatives;
use App\Actions\Simulations\ValidateScenario;
use App\Models\SimulationDataset;
use App\Models\SimulationScenario;
use App\Models\User;
use Database\Seeders\SimulationDatasetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SimulationScenarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_and_idempotent_save_use_server_values(): void
    {
        $this->seed(SimulationDatasetSeeder::class);
        $dataset = SimulationDataset::current();
        $user = User::factory()->member()->create();
        $payload = ['title' => 'Школы и безопасность', 'selections' => $dataset->data['example'], 'request_key' => (string) Str::uuid(), 'user_id' => 12345, 'result' => ['score' => 100], 'budget' => 9999];

        $this->actingAs($user)->postJson(route('scenarios.preview'), $payload)->assertJsonPath('result.score', '56.54307000')->assertJsonPath('result.cost', 95);
        $this->assertDatabaseCount('simulation_scenarios', 0);
        $this->post(route('scenarios.store'), $payload)->assertRedirect();
        $this->post(route('scenarios.store'), $payload)->assertRedirect();

        $this->assertDatabaseCount('simulation_scenarios', 1);
        $scenario = SimulationScenario::query()->sole();
        self::assertSame($user->id, $scenario->user_id);
        self::assertSame('56.54307000', $scenario->result['score']);
        $this->assertDatabaseCount('agent_runs', 0);
        $this->get(route('scenarios.show', $scenario))->assertInertia(fn (Assert $page) => $page->component('scenarios/show')->where('scenario.result.score', '56.54307000')->has('dataset.districts', 5));
    }

    #[DataProvider('invalidSets')]
    public function test_validator_rejects_invalid_decision_sets(array $selections, string $field): void
    {
        $data = SimulationDataset::factory()->make()->data;
        try {
            app(ValidateScenario::class)->handle($data, $selections);
            self::fail('Invalid scenario was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey($field, $exception->errors());
        }
    }

    /** @return array<string, array{array<mixed>, string}> */
    public static function invalidSets(): array
    {
        $example = [
            ['measure_id' => 'M7', 'district_id' => 'nura'], ['measure_id' => 'M8', 'district_id' => 'nura'],
            ['measure_id' => 'M10', 'district_id' => 'nura'], ['measure_id' => 'M12', 'district_id' => null], ['measure_id' => 'M5', 'district_id' => 'saryarka'],
        ];
        $sets = ['four' => [array_slice($example, 0, 4), 'selections'], 'six' => [[...$example, ['measure_id' => 'M9', 'district_id' => 'esil']], 'selections']];
        foreach ([
            'duplicate' => [4, ['measure_id' => 'M7', 'district_id' => 'esil'], 'selections.0.measure_id'],
            'missing district' => [0, ['measure_id' => 'M7', 'district_id' => null], 'selections.0.district_id'],
            'unknown district' => [0, ['measure_id' => 'M7', 'district_id' => 'foreign'], 'selections.0.district_id'],
            'city district' => [3, ['measure_id' => 'M12', 'district_id' => 'nura'], 'selections.3.district_id'],
            'unknown measure' => [4, ['measure_id' => 'M99', 'district_id' => 'esil'], 'selections.4.measure_id'],
            'three directions' => [4, ['measure_id' => 'M9', 'district_id' => 'esil'], 'directions'],
            'over budget' => [2, ['measure_id' => 'M13', 'district_id' => 'esil'], 'budget'],
            'land conflict' => [4, ['measure_id' => 'M4', 'district_id' => 'nura'], 'conflicts.M4'],
            'network conflict' => [2, ['measure_id' => 'M13', 'district_id' => 'saryarka'], 'conflicts.M5'],
        ] as $name => [$index, $selection, $field]) {
            $candidate = $example;
            $candidate[$index] = $selection;
            $sets[$name] = [$candidate, $field];
        }
        $candidate = $example;
        $candidate[0] = ['measure_id' => 'M1', 'district_id' => 'esil'];
        $candidate[1] = ['measure_id' => 'M3', 'district_id' => 'nura'];
        $sets['transport conflict across districts'] = [$candidate, 'conflicts.M1'];

        return $sets;
    }

    public function test_invalid_http_selection_does_not_save_or_dispatch(): void
    {
        $this->seed(SimulationDatasetSeeder::class);
        $this->actingAs(User::factory()->member()->create())->postJson(route('scenarios.store'), [
            'title' => 'Неполный', 'selections' => [['measure_id' => 'M1', 'district_id' => 'esil']], 'request_key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('selections');
        $this->assertDatabaseCount('simulation_scenarios', 0);
        $this->assertDatabaseCount('agent_runs', 0);
    }

    public function test_ownership_and_permissions_cover_copy_compare_and_creation(): void
    {
        $scenario = SimulationScenario::factory()->create();
        $this->actingAs(User::factory()->member()->create());
        $this->get(route('scenarios.show', $scenario))->assertForbidden();
        $this->get(route('scenarios.create', ['source' => $scenario->id]))->assertForbidden();
        $this->get(route('scenarios.compare', ['left' => $scenario->id, 'right' => $scenario->id]))->assertForbidden();
        $this->postJson(route('scenarios.preview'), ['selections' => $scenario->selections, 'source_scenario_id' => $scenario->id])->assertForbidden();
        $this->actingAs(User::factory()->observer()->create())->get(route('scenarios.create'))->assertForbidden();
    }

    public function test_guests_cannot_read_scenarios(): void
    {
        $this->get(route('scenarios.index'))->assertRedirect(route('login'));
    }

    public function test_alternatives_are_valid_stable_and_strictly_better(): void
    {
        $data = SimulationDataset::factory()->make()->data;
        $selections = app(ValidateScenario::class)->handle($data, $data['example']);
        $result = app(CalculateScenario::class)->handle($data, $selections);
        $finder = app(FindScenarioAlternatives::class);
        $alternatives = $finder->handle($data, $selections, $result);

        self::assertCount(3, $alternatives);
        self::assertSame($alternatives, $finder->handle($data, array_reverse($selections), $result));
        foreach ($alternatives as $alternative) {
            self::assertSame($alternative['selections'], app(ValidateScenario::class)->handle($data, $alternative['selections']));
            self::assertSame(1, bccomp($alternative['result']['score'], $result['score'], 8));
        }
        $result['score'] = '100';
        self::assertSame([], $finder->handle($data, $selections, $result));
    }

    public function test_seed_is_repeatable_and_scenarios_compare_only_with_matching_versions(): void
    {
        $this->seed(SimulationDatasetSeeder::class);
        $this->seed(SimulationDatasetSeeder::class);
        $this->assertDatabaseCount('simulation_datasets', 1);
        $user = User::factory()->member()->create();
        $first = SimulationScenario::factory()->for($user)->for(SimulationDataset::current(), 'dataset')->create();
        $same = SimulationScenario::factory()->for($user)->for($first->dataset, 'dataset')->create();
        $different = SimulationScenario::factory()->for($user)->create();

        $this->actingAs($user)->get(route('scenarios.compare', ['left' => $first->id, 'right' => $same->id]))->assertInertia(fn (Assert $page) => $page->component('scenarios/compare')->where('comparison.score', '0.00000000'));
        $this->get(route('scenarios.compare', ['left' => $first->id, 'right' => $different->id]))->assertUnprocessable();
    }
}
