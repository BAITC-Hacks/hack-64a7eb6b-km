<?php

namespace App\Filament\Resources\SimulationScenarios;

use App\Filament\Resources\SimulationScenarios\Pages\ListSimulationScenarios;
use App\Filament\Resources\SimulationScenarios\Pages\ViewSimulationScenario;
use App\Filament\Resources\SimulationScenarios\Schemas\SimulationScenarioInfolist;
use App\Filament\Resources\SimulationScenarios\Tables\SimulationScenariosTable;
use App\Models\SimulationScenario;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SimulationScenarioResource extends Resource
{
    protected static ?string $model = SimulationScenario::class;

    protected static ?string $modelLabel = 'сценарий';

    protected static ?string $pluralModelLabel = 'Сценарии города';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function canViewAny(): bool
    {
        return auth()->user()?->canAccessAdmin() === true;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return SimulationScenarioInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SimulationScenariosTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSimulationScenarios::route('/'),
            'view' => ViewSimulationScenario::route('/{record}'),
        ];
    }
}
