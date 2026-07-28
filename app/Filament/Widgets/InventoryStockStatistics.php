<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class InventoryStockStatistics extends StatsOverviewWidget
{
    #[\Override]
    protected function getStats(): array
    {
        $totals = InventoryStock::query()
            ->selectRaw('COALESCE(SUM(on_hand_quantity), 0) as on_hand')
            ->selectRaw('COALESCE(SUM(reserved_quantity), 0) as reserved')
            ->selectRaw('COALESCE(SUM(damaged_quantity), 0) as damaged')
            ->selectRaw('COALESCE(SUM(available_quantity), 0) as available')
            ->first();

        $inTransit = InventoryOperationLine::query()
            ->join('inventory_operations', 'inventory_operations.id', '=', 'inventory_operation_lines.inventory_operation_id')
            ->where('inventory_operations.operation_type', OperationType::InternalTransfer->value)
            ->where('inventory_operations.stage', OperationStage::InTransit->value)
            ->sum('inventory_operation_lines.quantity');

        return [
            Stat::make(__('admin.inventory.stock.on_hand_quantity'), $this->formatQuantity($totals, 'on_hand')),
            Stat::make(__('admin.inventory.stock.reserved_quantity'), $this->formatQuantity($totals, 'reserved')),
            Stat::make(__('admin.inventory.stock.damaged_quantity'), $this->formatQuantity($totals, 'damaged')),
            Stat::make(__('admin.inventory.stock.available_quantity'), $this->formatQuantity($totals, 'available')),
            Stat::make(__('admin.inventory.stock.in_transit_quantity'), $this->formatQuantity($inTransit)),
        ];
    }

    private function formatQuantity(InventoryStock|int|float|string|null $value, ?string $property = null): string
    {
        if ($value instanceof InventoryStock) {
            $value = $value->{$property};
        }

        return number_format((float) $value, 3);
    }
}
