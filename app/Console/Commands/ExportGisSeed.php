<?php

namespace App\Console\Commands;

use App\Actions\Gis\GisSeedPackage;
use Illuminate\Console\Command;

class ExportGisSeed extends Command
{
    protected $signature = 'gis:seed-export {--path= : New output directory} {--full : Include the full archive instead of the compact selection} {--freeze-incomplete : Freeze downloaded partial versions; stop GIS workers first}';

    protected $description = 'Export an offline GIS installation seed (compact, at most 50 MB by default)';

    public function handle(GisSeedPackage $package): int
    {
        if ($this->option('freeze-incomplete')) {
            $this->info('Сохранение доступных фрагментов растров и фиксация неполных слоёв…');
            $package->freezeIncomplete();
        }
        $this->info('Упаковка объектов и файлов локального архива…');
        $manifest = $package->export($this->option('path') ?: config('gis.seed_path'), compact: ! $this->option('full'));
        $this->info(sprintf('Seed: %d слоёв с данными, %d объектов, %d файлов, %s байт всего.', count(array_filter($manifest['layers'], fn (array $layer): bool => $layer['included'])), array_sum(array_column($manifest['layers'], 'features')), count($manifest['assets']), number_format($manifest['package_bytes'])));

        return self::SUCCESS;
    }
}
