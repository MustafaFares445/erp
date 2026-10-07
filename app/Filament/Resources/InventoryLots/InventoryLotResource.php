<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryLots;

use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\InventoryLots\Pages\ListInventoryLots;
use App\Filament\Resources\InventoryLots\Pages\ViewInventoryLot;
use App\Filament\Resources\InventoryLots\RelationManagers\LotBalancesRelationManager;
use App\Filament\Resources\InventoryLots\Schemas\InventoryLotInfolist;
use App\Filament\Resources\InventoryLots\Tables\InventoryLotsTable;
use App\Models\InventoryLot;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** @extends resource<InventoryLot> */
final class InventoryLotResource extends Resource
{
    protected static ?string $model = InventoryLot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.inventory';

    protected static ?int $navigationSort = 306;

    protected static ?string $recordTitleAttribute = 'lot_number';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.inventory_lots');
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return InventoryLotInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return InventoryLotsTable::configure($table);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return InventoryLot::query()
            ->canonical()
            ->with([
                'productVariant.product:id,name,name_ar',
                'conditionBalances.warehouse:id,code,name',
            ])
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->orderBy('id');
    }

    /** @return array<string> */
    #[\Override]
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'lot_number',
            'normalized_lot_number',
            'productVariant.sku',
            'productVariant.name',
            'productVariant.name_ar',
            'productVariant.product.name',
            'productVariant.product.name_ar',
        ];
    }

    #[\Override]
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        if (! $record instanceof InventoryLot) {
            return [];
        }

        return [
            'Product' => $record->productVariant->product->name ?? 'Unknown product',
            'SKU' => $record->productVariant->sku ?? 'No SKU',
            'Expiry' => $record->expires_at?->toDateString() ?? 'No expiry',
        ];
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            LotBalancesRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListInventoryLots::route('/'),
            'view' => ViewInventoryLot::route('/{record}'),
        ];
    }
}
