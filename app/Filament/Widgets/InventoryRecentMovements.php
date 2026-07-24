<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InventoryPermission;
use App\Models\InventoryMovement;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

final class InventoryRecentMovements extends TableWidget
{
    protected static ?string $heading = 'Recent stock movements';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(InventoryPermission::MovementView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => InventoryMovement::query()->with(['productVariant:id,sku,name', 'warehouse:id,name'])->latest()->limit(10))
            ->columns([
                TextColumn::make('created_at')->dateTime(),
                TextColumn::make('productVariant.sku')->label('SKU'),
                TextColumn::make('warehouse.name')->label('Warehouse'),
                TextColumn::make('movement_type')->badge(),
                TextColumn::make('quantity'),
            ]);
    }
}
