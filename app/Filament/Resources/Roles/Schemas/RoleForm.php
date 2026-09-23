<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Models\Permission;
use App\Models\Role;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Название роли')->required()->maxLength(255)->unique(ignoreRecord: true)->columnSpanFull()
                ->readOnly(fn (?Role $record): bool => $record?->isDefaultRole() ?? false)
                ->helperText(fn (?Role $record): ?string => $record?->isDefaultRole() ? 'Назначается новым пользователям. Название фиксировано, разрешения можно менять.' : null),
            Section::make('Разрешения')
                ->description('Разрешения действуют на фронтенде. Для входа в админпанель нужна роль super_admin. Изменения применяются ко всем пользователям роли.')
                ->schema([
                    CheckboxList::make('permission_ids')->label('Разрешённые действия')
                        ->options(fn (): array => Permission::options())->columns(2)->default([])
                        ->helperText('Для действий с запусками также нужен «Просмотр своих запусков и заметок».'),
                ])->columnSpanFull(),
        ]);
    }
}
