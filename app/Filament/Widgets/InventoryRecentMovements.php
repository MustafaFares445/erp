<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InventoryPermission;
use App\Enums\MovementType;
use App\Filament\Resources\StockMovements\Tables\StockMovementsTable;
use App\Models\InventoryMovement;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class InventoryRecentMovements extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(InventoryPermission::MovementView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->heading(__('admin.inventory.dashboard.recent_movements'))
            ->query(fn (): Builder => InventoryMovement::query()->with(['productVariant:id,sku,name', 'warehouse:id,name'])->latest()->limit(10))
            ->columns([
                TextColumn::make('created_at')->label(__('admin.inventory.movement.date'))->dateTime(),
                TextColumn::make('productVariant.sku')->label(__('admin.inventory.stock.variant')),
                TextColumn::make('warehouse.name')->label(__('admin.inventory.stock.warehouse_name')),
                TextColumn::make('movement_type')
                    ->label(__('admin.inventory.movement.type'))
                    ->badge()
                    ->formatStateUsing(fn (MovementType $state): string => Str::headline($state->value))
                    ->color(fn (MovementType $state): string => match ($state) {
                        MovementType::Sale, MovementType::Reservation, MovementType::Damage, MovementType::Disposal, MovementType::ServiceConsumption => 'danger',
                        MovementType::Return, MovementType::DamageRecovery => 'success',
                        MovementType::Adjustment, MovementType::Correction, MovementType::Transfer => 'info',
                        MovementType::Receipt => 'primary',
                    }),
                TextColumn::make('quantity')
                    ->label(__('admin.inventory.movement.quantity'))
                    ->formatStateUsing(fn (string $state): string => Str::startsWith($state, '-') ? $state : '+'.$state)
                    ->color(fn (string $state): string => Str::startsWith($state, '-') ? 'danger' : 'success'),
            ])
            ->recordUrl(fn (InventoryMovement $record): ?string => StockMovementsTable::sourceUrl($record));
    }
}
