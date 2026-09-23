<?php

namespace App\Filament\Resources\SimulationScenarios\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SimulationScenariosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Сценарий')->searchable(),
                TextColumn::make('user.email')->label('Владелец')->searchable(),
                TextColumn::make('dataset.version')->label('Данные'),
                TextColumn::make('result.score')->label('Score'),
                TextColumn::make('result.cost')->label('Расходы'),
                TextColumn::make('created_at')->label('Создан')->dateTime()->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
