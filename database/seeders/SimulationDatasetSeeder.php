<?php

namespace Database\Seeders;

use App\Models\SimulationDataset;
use Illuminate\Database\Seeder;
use LogicException;

class SimulationDatasetSeeder extends Seeder
{
    public function run(): void
    {
        $json = file_get_contents(database_path('seeders/data/astana-v1.json'));
        if ($json === false) {
            throw new LogicException('Simulation dataset is missing.');
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $checksum = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $dataset = SimulationDataset::query()->firstOrCreate(['version' => $data['version']], [
            'checksum' => $checksum, 'data' => $data,
        ]);
        if ($dataset->checksum !== $checksum) {
            throw new LogicException('An existing dataset version cannot be replaced.');
        }
    }
}
