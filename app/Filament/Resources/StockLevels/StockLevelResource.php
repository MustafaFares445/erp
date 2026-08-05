<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockLevels;

use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\Resources\StockLevels\Pages\ViewStockLevel;
use App\Filament\Resources\StockLevels\Schemas\StockLevelInfolist;
use App\Filament\Resources\StockLevels\Tables\StockLevelsTable;
use App\Models\InventoryStock;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class StockLevelResource extends Resource
{
    protected static ?string $model = InventoryStock::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.inventory';

    protected static ?int $navigationSort = 303;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.stock_levels');
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return self::withInTransitQuantity(parent::getEloquentQuery())
            ->with([
                // The type and weight columns need the product and the weight unit, so both are
                // eager-loaded here rather than resolved per row.
                'productVariant:id,sku,name,product_id,net_weight,weight_unit_id',
                'productVariant.product:id,product_type',
                'productVariant.weightUnit:id,symbol',
                'warehouse:id,code,name',
            ]);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function withInTransitQuantity(Builder $query): Builder
    {
        return $query->addSelect(['in_transit_quantity' => InventoryStock::inTransitQuantitySubquery()]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return StockLevelsTable::configure($table);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return StockLevelInfolist::configure($schema);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListStockLevels::route('/'),
            'view' => ViewStockLevel::route('/{record}'),
        ];
    }
}
