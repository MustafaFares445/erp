<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InventoryPermission;
use App\Models\InventoryStock;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

final class InventoryLowStock extends TableWidget
{
    protected static ?string $heading = 'Low stock';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(InventoryPermission::StockView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => InventoryStock::query()
                ->with(['productVariant:id,sku,name', 'warehouse:id,name'])
                ->whereNotNull('reorder_level')
                ->whereColumn('available_quantity', '<=', 'reorder_level')
                ->orderBy('available_quantity'))
            ->columns([
                TextColumn::make('productVariant.sku')->label('SKU'),
                TextColumn::make('productVariant.name')->label('Variant'),
                TextColumn::make('warehouse.name')->label('Warehouse'),
                TextColumn::make('available_quantity')->label('Available'),
                TextColumn::make('reorder_level')->label('Reorder level'),
            ]);
    }
}
