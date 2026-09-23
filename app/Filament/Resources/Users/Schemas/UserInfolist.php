<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\AccessPermission;
use App\Models\User;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Учётная запись')->schema([
                TextEntry::make('name')->label('Имя'),
                TextEntry::make('email')->label('Email')->copyable(),
                IconEntry::make('email_verified_at')->label('Email подтверждён')->boolean(),
                TextEntry::make('created_at')->label('Создан')->dateTime('d.m.Y H:i'),
            ])->columns(2)->columnSpanFull(),
            Section::make('Доступы')->schema([
                TextEntry::make('roles.name')->label('Роли')->badge()->placeholder('Без роли'),
                TextEntry::make('effective_permissions')->label('Все действующие разрешения')
                    ->state(fn (User $record): array => $record->getAllPermissions()->pluck('name')
                        ->map(fn (string $name): string => AccessPermission::tryFrom($name)?->label() ?? $name)->all())
                    ->listWithLineBreaks()->placeholder('Нет разрешений'),
                TextEntry::make('demo_notice')->label('Демо-аккаунт')
                    ->state('Логин, пароль и роль этого аккаунта фиксированы для демонстрации.')
                    ->visible(fn (User $record): bool => $record->isDemoAdministrator()),
            ])->columnSpanFull(),
        ]);
    }
}
