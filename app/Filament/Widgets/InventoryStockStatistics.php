<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class InventoryStockStatistics extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    #[\Override]
    protected function getStats(): array
    {
        $totals = InventoryStock::query()
            ->selectRaw('COALESCE(SUM(on_hand_quantity), 0) as on_hand_quantity')
            ->selectRaw('COALESCE(SUM(reserved_quantity), 0) as reserved_quantity')
            ->selectRaw('COALESCE(SUM(damaged_quantity), 0) as damaged_quantity')
            ->selectRaw('COALESCE(SUM(available_quantity), 0) as available_quantity')
            ->first();

        $inTransit = InventoryOperationLine::query()
            ->join('inventory_operations', 'inventory_operations.id', '=', 'inventory_operation_lines.inventory_operation_id')
            ->where('inventory_operations.operation_type', OperationType::InternalTransfer->value)
            ->whereIn('inventory_operations.stage', [OperationStage::InTransit->value, OperationStage::PartiallyReceived->value])
            ->selectRaw('coalesce(sum(inventory_operation_lines.dispatched_base_quantity - inventory_operation_lines.received_base_quantity), 0) as in_transit_quantity')
            ->value('in_transit_quantity');

        return [
            Stat::make(__('admin.inventory.stock.on_hand_quantity'), $this->formatQuantity($totals?->on_hand_quantity))
                ->icon(Heroicon::OutlinedCube)
                ->color('gray'),
            Stat::make(__('admin.inventory.stock.reserved_quantity'), $this->formatQuantity($totals?->reserved_quantity))
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('warning'),
            Stat::make(__('admin.inventory.stock.damaged_quantity'), $this->formatQuantity($totals?->damaged_quantity))
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger'),
            Stat::make(__('admin.inventory.stock.available_quantity'), $this->formatQuantity($totals?->available_quantity))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success'),
            Stat::make(__('admin.inventory.stock.in_transit_quantity'), $this->formatQuantity($inTransit))
                ->icon(Heroicon::OutlinedTruck)
                ->color('info'),
        ];
    }

    private function formatQuantity(mixed $value): string
    {
        return number_format(is_numeric($value) ? (float) $value : 0, 3);
    }
}
