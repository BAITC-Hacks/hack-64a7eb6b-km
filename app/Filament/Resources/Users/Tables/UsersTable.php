<?php

namespace App\Filament\Resources\Users\Tables;

use App\Actions\Access\DeleteUser;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Имя')->searchable()->sortable(),
            TextColumn::make('email')->label('Email')->searchable()->sortable()->copyable(),
            TextColumn::make('roles.name')->label('Роли')->badge()->placeholder('Без роли'),
            IconColumn::make('email_verified_at')->label('Email подтверждён')->boolean(),
            TextColumn::make('created_at')->label('Создан')->dateTime('d.m.Y H:i')->sortable(),
        ])->filters([
            SelectFilter::make('roles')->label('Роль')->relationship('roles', 'name')->multiple()->preload(),
            TernaryFilter::make('email_verified_at')->label('Email подтверждён')->nullable(),
        ])->recordActions([
            ViewAction::make()->label('Просмотр'),
            EditAction::make()->label('Изменить'),
            DeleteAction::make()->label('Удалить')
                ->modalDescription('Пользователь и связанные с ним запуски и заметки будут удалены.')
                ->using(fn (User $record): bool => app(DeleteUser::class)->handle(auth()->user(), $record)),
        ])->defaultSort('created_at', 'desc');
    }
}
