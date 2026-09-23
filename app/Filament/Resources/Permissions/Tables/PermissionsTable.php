<?php

namespace App\Filament\Resources\Permissions\Tables;

use App\Models\Permission;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PermissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Разрешение')->searchable()
                ->formatStateUsing(fn (Permission $record): string => $record->label())
                ->description(fn (Permission $record): string => $record->name),
            TextColumn::make('group')->label('Раздел')->state(fn (Permission $record): string => $record->group()),
            TextColumn::make('roles.name')->label('Роли')->badge()->placeholder('Не назначено'),
            TextColumn::make('users_count')->label('Прямые назначения')->counts('users'),
        ])->defaultSort('id')->paginated(false);
    }
}
