<?php

namespace App\Filament\Resources\Roles\Tables;

use App\Actions\Access\DeleteRole;
use App\Models\Role;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Роль')->searchable()->sortable()
                ->description(fn (Role $record): string => $record->isSuperAdmin() ? 'Админпанель и фронтенд' : 'Фронтенд'),
            TextColumn::make('users_count')->label('Пользователей')->counts('users'),
            TextColumn::make('permissions_count')->label('Разрешений')->counts('permissions'),
            TextColumn::make('updated_at')->label('Обновлена')->dateTime('d.m.Y H:i')->sortable(),
        ])->recordActions([
            ViewAction::make()->label('Просмотр'),
            EditAction::make()->label('Изменить'),
            DeleteAction::make()->label('Удалить')
                ->using(fn (Role $record): bool => app(DeleteRole::class)->handle(auth()->user(), $record)),
        ])->defaultSort('name');
    }
}
