<?php

namespace Database\Factories;

use App\Actions\Simulations\CalculateScenario;
use App\Actions\Simulations\FindScenarioAlternatives;
use App\Models\SimulationDataset;
use App\Models\SimulationScenario;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SimulationScenario> */
class SimulationScenarioFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->member(),
            'simulation_dataset_id' => SimulationDataset::factory(),
            'title' => 'Сценарий из задания',
            'request_key' => fake()->uuid(),
            'calculator_version' => CalculateScenario::VERSION,
            'selections' => fn (array $attributes): array => SimulationDataset::query()->whereKey($attributes['simulation_dataset_id'])->firstOrFail()->data['example'],
            'result' => fn (array $attributes): array => app(CalculateScenario::class)->handle(SimulationDataset::query()->whereKey($attributes['simulation_dataset_id'])->firstOrFail()->data, $attributes['selections']),
            'alternatives' => fn (array $attributes): array => app(FindScenarioAlternatives::class)->handle(SimulationDataset::query()->whereKey($attributes['simulation_dataset_id'])->firstOrFail()->data, $attributes['selections'], $attributes['result']),
        ];
    }
}
