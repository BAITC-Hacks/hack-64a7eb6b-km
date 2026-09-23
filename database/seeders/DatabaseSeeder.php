<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([AccessControlSeeder::class, DemoUsersSeeder::class, SimulationDatasetSeeder::class, GisDatasetSeeder::class]);
    }
}
