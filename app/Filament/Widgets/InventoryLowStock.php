<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InventoryPermission;
use App\Models\InventoryStock;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

final class InventoryLowStock extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(InventoryPermission::StockView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->heading(__('admin.inventory.dashboard.reorder_needed'))
            ->query(fn (): Builder => InventoryStock::query()
                ->with(['productVariant:id,sku,name', 'warehouse:id,name'])
                ->where(function (Builder $query): void {
                    $query->where('available_quantity', '<=', 0)
                        ->orWhere(function (Builder $query): void {
                            $query->whereNotNull('reorder_level')->whereColumn('available_quantity', '<=', 'reorder_level');
                        });
                })
                ->orderBy('available_quantity'))
            ->columns([
                TextColumn::make('productVariant.sku')->label(__('admin.inventory.stock.variant')),
                TextColumn::make('productVariant.name')->label(__('admin.inventory.stock.variant_name')),
                TextColumn::make('warehouse.name')->label(__('admin.inventory.stock.warehouse_name')),
                TextColumn::make('available_quantity')
                    ->label(__('admin.inventory.stock.available_quantity'))
                    ->color(fn (InventoryStock $record): string => (float) $record->available_quantity <= 0 ? 'danger' : 'warning')
                    ->weight(FontWeight::Medium),
                TextColumn::make('reorder_level')->label(__('admin.inventory.stock.reorder_level')),
            ]);
    }
}
