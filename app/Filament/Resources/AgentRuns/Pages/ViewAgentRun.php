<?php

namespace App\Filament\Resources\AgentRuns\Pages;

use App\Filament\Resources\AgentRuns\AgentRunResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAgentRun extends ViewRecord
{
    protected static string $resource = AgentRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
