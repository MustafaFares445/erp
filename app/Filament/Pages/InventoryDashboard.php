<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\InventoryPermission;
use App\Filament\Widgets\InventoryKeyMetrics;
use App\Filament\Widgets\InventoryLowStock;
use App\Filament\Widgets\InventoryMovementsTrend;
use App\Filament\Widgets\InventoryOperationsPipeline;
use App\Filament\Widgets\InventoryPendingDocuments;
use App\Filament\Widgets\ReconciliationStatus;
use App\Filament\Widgets\InventoryRecentMovements;
use App\Filament\Widgets\InventoryStockStatistics;
use App\Filament\Widgets\InventoryStockValue;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Inventory's module landing page: headline KPIs and the operations
 * pipeline first, then the value/trend charts side by side, then the
 * actionable tables, with the raw stock composition breakdown last.
 */
final class InventoryDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    #[\Override]
    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user?->can(InventoryPermission::StockView->value) ?? false) {
            return true;
        }
        if ($user?->can(InventoryPermission::AdjustmentView->value) ?? false) {
            return true;
        }
        if ($user?->can(InventoryPermission::TransferView->value) ?? false) {
            return true;
        }

        return (bool) ($user?->can(InventoryPermission::MovementView->value) ?? false);
    }

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.dashboard');
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.inventory_dashboard');
    }

    #[\Override]
    public function getHeaderWidgetsColumns(): array
    {
        return ['lg' => 2];
    }

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [
            InventoryKeyMetrics::class,
            ReconciliationStatus::class,
            InventoryOperationsPipeline::class,
            InventoryPendingDocuments::class,
            InventoryStockValue::class,
            InventoryMovementsTrend::class,
            InventoryLowStock::class,
            InventoryRecentMovements::class,
            InventoryStockStatistics::class,
        ];
    }
}
