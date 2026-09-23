<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Permission;
use App\Models\Role;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Учётная запись')->schema([
                TextInput::make('name')->label('Имя')->required()->maxLength(255),
                TextInput::make('email')->label('Email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                TextInput::make('password')->label('Пароль')->password()->revealable()->autocomplete('new-password')
                    ->rules([Password::defaults()])->maxLength(255)->required(fn (string $operation): bool => $operation === 'create')
                    ->afterStateHydrated(fn (TextInput $component) => $component->state(''))
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText('При редактировании оставьте пустым, чтобы сохранить пароль.'),
                Toggle::make('verified')->label('Email подтверждён')->default(true)
                    ->helperText('Для входа в админпанель нужен подтверждённый email.'),
            ])->columns(2)->columnSpanFull(),
            Section::make('Роли и доступы')
                ->description('Только super_admin открывает админпанель. Остальные роли и дополнительные разрешения управляют доступом на фронтенде.')
                ->visible(fn (): bool => auth()->user()->canAccessAdmin())
                ->schema([
                    Select::make('role_ids')->label('Роли')->placeholder('Выберите роли')->multiple()->searchable()->preload()
                        ->options(fn (): array => Role::query()->where('guard_name', 'web')->orderBy('name')->pluck('name', 'id')->all())
                        ->default(fn (): array => [Role::findByName(Role::AKIM, 'web')->id]),
                    CheckboxList::make('permission_ids')->label('Дополнительные разрешения')
                        ->options(fn (): array => Permission::options())->columns(2)->default([])
                        ->helperText('Дополняют права ролей только для этого пользователя.'),
                ])->columnSpanFull(),
        ]);
    }
}
