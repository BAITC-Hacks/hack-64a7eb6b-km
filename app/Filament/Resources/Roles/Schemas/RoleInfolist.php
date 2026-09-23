<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Enums\AccessPermission;
use App\Models\Role;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class RoleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label('Название роли'),
            TextEntry::make('users_count')->label('Пользователей')->state(fn (Role $record): int => $record->users()->count()),
            TextEntry::make('permissions.name')->label('Разрешения')->listWithLineBreaks()->columnSpanFull()
                ->formatStateUsing(fn (string $state): string => AccessPermission::tryFrom($state)?->label() ?? $state)->placeholder('Нет разрешений'),
            TextEntry::make('system_notice')->label('Системная роль')
                ->state('super_admin — единственная роль с доступом в админпанель. Она также получает все права фронтенда. Эту роль нельзя изменить или удалить.')
                ->visible(fn (Role $record): bool => $record->isSuperAdmin())->columnSpanFull(),
        ]);
    }
}
