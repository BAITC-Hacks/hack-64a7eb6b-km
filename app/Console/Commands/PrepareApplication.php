<?php

namespace App\Console\Commands;

use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\GisDatasetSeeder;
use Database\Seeders\SimulationDatasetSeeder;
use Illuminate\Console\Command;

class PrepareApplication extends Command
{
    protected $signature = 'app:prepare';

    protected $description = 'Initialize a local application without rotating an existing key or dropping data';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Use explicit deployment steps outside local and testing environments.');

            return self::FAILURE;
        }
        if (blank(config('app.key')) && $this->call('key:generate') !== self::SUCCESS) {
            return self::FAILURE;
        }

        if ($this->call('migrate', ['--no-interaction' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        if ($this->call('db:seed', [
            '--class' => DemoUsersSeeder::class,
            '--no-interaction' => true,
        ]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        foreach ([SimulationDatasetSeeder::class, GisDatasetSeeder::class] as $seeder) {
            if ($this->call('db:seed', ['--class' => $seeder, '--no-interaction' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
