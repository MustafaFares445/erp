<?php

declare(strict_types=1);

namespace App\Filament\Resources\Warehouses;

use App\Filament\Resources\Warehouses\Pages\CreateWarehouse;
use App\Filament\Resources\Warehouses\Pages\EditWarehouse;
use App\Filament\Resources\Warehouses\Pages\ListWarehouses;
use App\Filament\Resources\Warehouses\Pages\ViewWarehouse;
use App\Filament\Resources\Warehouses\RelationManagers\StockLevelsRelationManager;
use App\Filament\Resources\Warehouses\Schemas\WarehouseForm;
use App\Filament\Resources\Warehouses\Schemas\WarehouseInfolist;
use App\Filament\Resources\Warehouses\Tables\WarehousesTable;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

final class WarehouseResource extends Resource
{
    protected static ?string $model = Warehouse::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    /**
     * Matches the inventory group's translation key (AdminModuleRegistry
     * §1.2 convention). The panel's custom navigation builder places items
     * by module rather than Filament's automatic grouping, so this mainly
     * documents intent and keeps the resource forward-compatible.
     */
    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.inventory';

    /**
     * Inventory group sort (3) * 100 + this item's index (2) in
     * AdminModuleRegistry's `inventory` group items list.
     */
    protected static ?int $navigationSort = 302;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.warehouses');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return WarehouseForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return WarehouseInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return WarehousesTable::configure($table);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            StockLevelsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListWarehouses::route('/'),
            'create' => CreateWarehouse::route('/create'),
            'view' => ViewWarehouse::route('/{record}'),
            'edit' => EditWarehouse::route('/{record}/edit'),
        ];
    }

    #[\Override]
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
