<?php

namespace App\Console\Commands;

use Database\Seeders\DemoAdminSeeder;
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
            '--class' => DemoAdminSeeder::class,
            '--no-interaction' => true,
        ]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        return $this->call('db:seed', [
            '--class' => SimulationDatasetSeeder::class,
            '--no-interaction' => true,
        ]);
    }
}
