<?php

namespace Database\Factories;

use App\Models\SimulationDataset;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SimulationDataset> */
class SimulationDatasetFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $data = json_decode((string) file_get_contents(database_path('seeders/data/astana-v1.json')), true, 512, JSON_THROW_ON_ERROR);
        $data['version'] = 'test-'.fake()->uuid();

        return ['version' => $data['version'], 'checksum' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), 'data' => $data];
    }
}
