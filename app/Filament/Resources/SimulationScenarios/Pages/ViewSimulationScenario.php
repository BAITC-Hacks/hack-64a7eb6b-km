<?php

namespace App\Filament\Resources\SimulationScenarios\Pages;

use App\Filament\Resources\SimulationScenarios\SimulationScenarioResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSimulationScenario extends ViewRecord
{
    protected static string $resource = SimulationScenarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
