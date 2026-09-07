<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InventoryPermission;
use App\Enums\InventoryReportType;
use App\Enums\StockCondition;
use App\Filament\Resources\InventoryReports\InventoryReportResource;
use App\Models\InventoryConditionBalance;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The damaged-stock work queue (WP-3.3, GAP-UI-06, IN-07) — every warehouse
 * currently holding damaged quantity, so it stays visible as work to
 * recover or dispose rather than sitting unnoticed in a condition column.
 */
final class DamagedStockQueue extends StatsOverviewWidget
{
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
            Stat::make(__('admin.inventory.dashboard.damaged_stock_count'), (string) $rows->count())
                ->description(__('admin.inventory.dashboard.damaged_stock_quantity', [
                    'quantity' => number_format($quantity, 6, '.', ''),
                ]))
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color($rows->isEmpty() ? 'success' : 'danger')
                ->url(InventoryReportResource::getUrl('index', [
                    'activeTab' => InventoryReportType::ConditionChanges->value,
                ])),
        ];
    }
}
