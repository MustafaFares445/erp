<?php

declare(strict_types=1);

namespace App\Filament\Resources\SerializedInventoryUnits;

use App\Filament\Resources\SerializedInventoryUnits\Pages\ListSerializedInventoryUnits;
use App\Filament\Resources\SerializedInventoryUnits\Pages\ViewSerializedInventoryUnit;
use App\Filament\Resources\SerializedInventoryUnits\Schemas\SerializedInventoryUnitInfolist;
use App\Filament\Resources\SerializedInventoryUnits\Tables\SerializedInventoryUnitsTable;
use App\Models\SerializedInventoryUnit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class SerializedInventoryUnitResource extends Resource
{
    protected static ?string $model = SerializedInventoryUnit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.inventory';

    protected static ?int $navigationSort = 305;

    protected static ?string $recordTitleAttribute = 'serial_number';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.serialized_inventory_units');
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return SerializedInventoryUnitInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return SerializedInventoryUnitsTable::configure($table);
    }

    /** @return array<string> */
    #[\Override]
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'serial_number',
            'iot_number',
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
        if (! $record instanceof SerializedInventoryUnit) {
            return [];
        }

        return [
            'SKU' => $record->productVariant->sku ?? 'Unknown',
            'Warehouse' => $record->warehouse->code ?? 'No warehouse',
        ];
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'productVariant.product:id,name,name_ar',
            'warehouse:id,code,name',
            'receiptItem.receipt:id,receipt_number',
        ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSerializedInventoryUnits::route('/'),
            'view' => ViewSerializedInventoryUnit::route('/{record}'),
        ];
    }
}
