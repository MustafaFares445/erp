<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockLevels\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class StockLevelInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextEntry::make('productVariant.sku')->label(__('admin.inventory.stock.variant')),
                TextEntry::make('productVariant.name')->label(__('admin.inventory.stock.variant_name')),
                TextEntry::make('warehouse.code')->label(__('admin.inventory.stock.warehouse')),
                TextEntry::make('warehouse.name')->label(__('admin.inventory.stock.warehouse_name')),
                TextEntry::make('on_hand_quantity')->numeric(decimalPlaces: 3),
                TextEntry::make('reserved_quantity')->numeric(decimalPlaces: 3),
                TextEntry::make('damaged_quantity')->numeric(decimalPlaces: 3),
                TextEntry::make('available_quantity')->numeric(decimalPlaces: 3),
                TextEntry::make('reorder_level')->numeric(decimalPlaces: 3),
            ]),
        ]);
    }
}
