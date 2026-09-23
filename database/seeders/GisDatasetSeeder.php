<?php

namespace Database\Seeders;

use App\Actions\Gis\GisSeedPackage;
use Illuminate\Database\Seeder;

class GisDatasetSeeder extends Seeder
{
    public function run(GisSeedPackage $package): void
    {
        if (! config('gis.seed_enabled')) {
            return;
        }
        $loaded = $package->restore(config('gis.seed_path'));
        $this->command->info($loaded ? 'GIS: локальные данные восстановлены без запросов к API.' : 'GIS: существующий архив сохранён, повторная загрузка seed не требуется.');
    }
}
