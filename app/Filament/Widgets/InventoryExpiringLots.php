<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InventoryPermission;
use App\Filament\Resources\InventoryLots\InventoryLotResource;
use App\Filament\Widgets\Concerns\BuildsDashboardTables;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\InventoryLotBalance;
use App\Models\InventorySetting;
use App\Support\QuantityFormatter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

final class InventoryExpiringLots extends TableWidget
{
    protected static bool $isLazy = false;

    use BuildsDashboardTables;
    use InteractsWithDashboardFilters;

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(InventoryPermission::StockView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        $noticeDays = InventorySetting::expiryWindows()['notice'];

        return $this->dashboardTable($table)
            ->heading(__('Expiring Lots'))
            ->query(fn (): Builder => InventoryLotBalance::query()
                // Filament adds an ID sort for pagination; keep it outside the grouped query.
                ->fromSub(
                    InventoryLotBalance::query()
                        ->join('inventory_lots as expiring_lots', 'expiring_lots.id', '=', 'inventory_lot_balances.inventory_lot_id')
                        ->selectRaw('MIN(inventory_lot_balances.id) as id')
                        ->selectRaw('inventory_lot_balances.inventory_lot_id')
                        ->selectRaw('inventory_lot_balances.warehouse_id')
                        ->selectRaw('SUM(inventory_lot_balances.on_hand_base_quantity) as on_hand_base_quantity')
                        ->selectRaw('MIN(expiring_lots.expires_at) as expiry_sort')
                        ->where('inventory_lot_balances.on_hand_base_quantity', '>', 0)
                        ->whereNotNull('expiring_lots.expires_at')
                        ->whereDate('expiring_lots.expires_at', '<=', today()->addDays($noticeDays))
                        ->when(
                            $this->dashboardFilter('warehouseId'),
                            static fn (Builder $query, int $warehouseId): Builder => $query->where('inventory_lot_balances.warehouse_id', $warehouseId),
                        )
                        ->groupBy('inventory_lot_balances.inventory_lot_id', 'inventory_lot_balances.warehouse_id'),
                    'inventory_lot_balances',
                )
                ->with([
                    'warehouse:id,name',
                    'lot:id,product_variant_id,lot_number,expires_at',
                    'lot.productVariant:id,product_id,sku,name',
                    'lot.productVariant.product:id,name',
                ])
                ->orderBy('expiry_sort')
                ->orderBy('inventory_lot_balances.inventory_lot_id')
                ->orderBy('inventory_lot_balances.warehouse_id'))
            ->recordUrl(fn (InventoryLotBalance $record): string => InventoryLotResource::getUrl('view', ['record' => $record->inventory_lot_id]))
            ->columns([
                TextColumn::make('lot.productVariant.product.name')->wrap()
                    ->label(__('Product'))
                    ->description(fn (InventoryLotBalance $record): ?string => $record->lot?->productVariant?->sku),
                TextColumn::make('lot.lot_number')
                    ->label(__('Lot'))
                    ->placeholder(__('—')),
                TextColumn::make('warehouse.name')->toggleable(isToggledHiddenByDefault: true)->wrap()
                    ->label(__('Warehouse')),
                TextColumn::make('on_hand_base_quantity')
                    ->label(__('Quantity'))
                    ->formatStateUsing(fn (mixed $state): string => QuantityFormatter::display($state)),
                TextColumn::make('lot.expires_at')->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('Expiry Date'))
                    ->date()
                    ->color(fn (InventoryLotBalance $record): string => ($record->lot?->daysRemaining() ?? 9999) < 0 ? 'danger' : 'warning'),
                TextColumn::make('days_remaining')
                    ->label(__('Days Remaining'))
                    ->state(fn (InventoryLotBalance $record): ?int => $record->lot?->daysRemaining())
                    ->badge()
                    ->color(fn (InventoryLotBalance $record): string => match (true) {
                        ($record->lot?->daysRemaining() ?? 9999) < 0 => 'danger',
                        ($record->lot?->daysRemaining() ?? 9999) <= 30 => 'danger',
                        ($record->lot?->daysRemaining() ?? 9999) <= 60 => 'warning',
                        default => 'info',
                    }),
            ]);
    }
}
