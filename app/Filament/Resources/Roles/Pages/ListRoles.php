<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Создать роль')];
    }

    public function getSubheading(): ?string
    {
        return 'Роль объединяет разрешения. Перед удалением роли снимите её со всех пользователей.';
    }
}
