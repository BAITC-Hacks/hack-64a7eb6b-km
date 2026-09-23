<?php

namespace App\Filament\Resources\Permissions;

use App\Enums\AccessPermission;
use App\Filament\Resources\Permissions\Pages\ListPermissions;
use App\Filament\Resources\Permissions\Tables\PermissionsTable;
use App\Models\Permission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PermissionResource extends Resource
{
    protected static ?string $model = Permission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $modelLabel = 'разрешение';

    protected static ?string $pluralModelLabel = 'Разрешения';

    protected static string|UnitEnum|null $navigationGroup = 'Управление доступом';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return PermissionsTable::configure($table);
    }

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('guard_name', 'web')->whereIn('name', array_column(AccessPermission::cases(), 'value'));
    }

    public static function getPages(): array
    {
        return ['index' => ListPermissions::route('/')];
    }
}
