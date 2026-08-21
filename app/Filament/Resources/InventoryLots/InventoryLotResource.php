<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryLots;

use App\Filament\Resources\InventoryLots\Pages\ListInventoryLots;
use App\Filament\Resources\InventoryLots\Pages\ViewInventoryLot;
use App\Filament\Resources\InventoryLots\Schemas\InventoryLotInfolist;
use App\Filament\Resources\InventoryLots\Tables\InventoryLotsTable;
use App\Models\InventoryLot;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

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
        return parent::getEloquentQuery()
            ->with([
                'productVariant.product:id,name,name_ar',
                'warehouse:id,code,name',
                'receiptItem.receipt:id,receipt_number',
            ])
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->orderBy('id');
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
