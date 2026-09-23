<?php

namespace App\Filament\Resources\Permissions\Pages;

use App\Filament\Resources\Permissions\PermissionResource;
use Filament\Resources\Pages\ListRecords;

class ListPermissions extends ListRecords
{
    protected static string $resource = PermissionResource::class;

    public function getSubheading(): ?string
    {
        return 'Доступные действия приложения. Назначайте разрешения в ролях или карточках пользователей.';
    }
}
