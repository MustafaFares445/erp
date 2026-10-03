<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InventoryPermission;
use App\Enums\MovementType;
use App\Filament\Resources\StockMovements\Tables\StockMovementsTable;
use App\Filament\Widgets\Concerns\BuildsDashboardTables;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\InventoryMovement;
use App\Support\QuantityFormatter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The latest stock movements in the selected window, optionally for one
 * warehouse, each linking to its source document.
 */
final class InventoryRecentMovements extends TableWidget
{
    use BuildsDashboardTables;
    use InteractsWithDashboardFilters;

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(InventoryPermission::MovementView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $this->dashboardTable($table)
            ->heading(__('admin.inventory.dashboard.recent_movements'))
            ->query(function (): Builder {
                $period = $this->dashboardPeriod();

                return InventoryMovement::query()
                    ->with(['productVariant:id,sku,name', 'warehouse:id,name', 'transactionUnit:id,symbol'])
                    ->whereBetween('created_at', [$period->from, $period->to])
                    ->when($this->dashboardFilter('warehouseId'), static fn (Builder $query, int $warehouseId): Builder => $query->where('warehouse_id', $warehouseId))
                    ->latest();
            })
            ->columns([
                TextColumn::make('productVariant.sku')
                    ->label(__('admin.inventory.stock.variant'))
                    ->description(fn (InventoryMovement $record): ?string => $record->warehouse?->name)
                    ->weight('medium'),
                TextColumn::make('movement_type')
                    ->label(__('admin.inventory.movement.type'))
                    ->badge()
                    ->color(fn (MovementType $state): string => match ($state) {
                        MovementType::Sale, MovementType::Reservation, MovementType::Damage, MovementType::Disposal, MovementType::ServiceConsumption => 'danger',
                        MovementType::Return, MovementType::DamageRecovery => 'success',
                        MovementType::Adjustment, MovementType::Correction, MovementType::Transfer => 'info',
                        MovementType::Receipt => 'primary',
                    }),
                TextColumn::make('base_quantity_delta')
                    ->label(__('admin.inventory.movement.base_quantity_delta'))
                    ->state(fn (InventoryMovement $record): string => (string) ($record->base_quantity_delta ?? $record->quantity))
                    ->formatStateUsing(fn (string $state): string => Str::startsWith($state, '-')
                        ? QuantityFormatter::display($state)
                        : '+'.QuantityFormatter::display($state))
                    ->description(fn (InventoryMovement $record): ?string => $record->transactionUnit?->symbol)
                    ->color(fn (string $state): string => Str::startsWith($state, '-') ? 'danger' : 'success'),
                TextColumn::make('created_at')
                    ->label(__('admin.inventory.movement.date'))
                    ->since()
                    ->dateTimeTooltip(),
            ])
            ->recordUrl(fn (InventoryMovement $record): ?string => StockMovementsTable::sourceUrl($record));
    }
}
