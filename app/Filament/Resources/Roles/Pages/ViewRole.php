<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRole extends ViewRecord
{
    protected static string $resource = RoleResource::class;

    protected static ?string $title = 'Просмотр роли';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
