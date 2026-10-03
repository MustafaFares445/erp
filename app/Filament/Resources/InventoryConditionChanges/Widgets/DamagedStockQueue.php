<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryConditionChanges\Widgets;

use App\Enums\InventoryPermission;
use App\Enums\StockCondition;
use App\Models\InventoryConditionBalance;
use App\Support\QuantityFormatter;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * The damaged-stock work queue (WP-3.3, GAP-UI-06, IN-07) — every warehouse
 * currently holding damaged quantity, shown above the condition-change list
 * where recovery and disposal documents are raised, so it stays visible as
 * work rather than sitting unnoticed in a condition column.
 */
final class DamagedStockQueue extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(InventoryPermission::StockView->value) ?? false;
    }

    #[\Override]
    protected function getStats(): array
    {
        $rows = InventoryConditionBalance::query()
            ->where('stock_condition', StockCondition::Damaged->value)
            ->where('on_hand_base_quantity', '>', 0)
            ->get();

        $quantity = $rows->sum(
            static fn (InventoryConditionBalance $balance): float => (float) $balance->on_hand_base_quantity,
        );

        return [
            Stat::make(__('admin.inventory.dashboard.damaged_stock_count'), Number::format($rows->count()))
                ->description(__('admin.inventory.dashboard.damaged_stock_quantity', [
                    'quantity' => QuantityFormatter::display($quantity),
                ]))
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color($rows->isEmpty() ? 'success' : 'danger'),
        ];
    }
}
