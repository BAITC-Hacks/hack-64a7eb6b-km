<?php

namespace App\Filament\Resources\SimulationScenarios\Pages;

use App\Filament\Resources\SimulationScenarios\SimulationScenarioResource;
use Filament\Resources\Pages\ListRecords;

class ListSimulationScenarios extends ListRecords
{
    protected static string $resource = SimulationScenarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
