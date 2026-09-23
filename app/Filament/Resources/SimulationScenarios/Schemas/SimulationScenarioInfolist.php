<?php

namespace App\Filament\Resources\SimulationScenarios\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class SimulationScenarioInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('title')->label('Название'),
                TextEntry::make('user.email')->label('Владелец'),
                TextEntry::make('dataset.version')->label('Версия данных'),
                TextEntry::make('calculator_version')->label('Версия расчёта'),
                TextEntry::make('result.score')->label('Score'),
                TextEntry::make('result.cost')->label('Расходы'),
                TextEntry::make('source_scenario_id')->label('Исходный сценарий'),
                TextEntry::make('created_at')->dateTime()->label('Создан'),
                RepeatableEntry::make('selections')->label('Решения')->schema([
                    TextEntry::make('measure_id')->label('Мера'),
                    TextEntry::make('district_id')->placeholder('Весь город')->label('Район'),
                ])->columns(2)->columnSpanFull(),
                RepeatableEntry::make('result.districts')->label('Районы')->schema([
                    TextEntry::make('name')->label('Район'),
                    TextEntry::make('score')->label('Score'),
                ])->columns(2)->columnSpanFull(),
            ]);
    }
}
