<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\InventoryPermission;
use App\Enums\OperationType;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Filament\Resources\InventoryCounts\InventoryCountResource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Widgets\InventoryKeyMetrics;
use App\Filament\Widgets\InventoryLowStock;
use App\Filament\Widgets\InventoryMovementsTrend;
use App\Filament\Widgets\InventoryRecentMovements;
use App\Filament\Widgets\InventoryStockValue;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;

/**
 * Inventory's module landing page, kept to the essentials: headline KPIs,
 * movement flow beside stock value, then low stock beside the latest
 * movements — all narrowable to one warehouse. Deeper analysis lives on the
 * dedicated resource pages.
 */
final class InventoryDashboard extends ModuleDashboard
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
    public function getTitle(): string
    {
        return __('admin.resources.inventory_dashboard');
    }

    /**
     * The dashboard's own filter reset plus the quick actions that start Inventory work. Each is
     * permission-gated, so a stock-only user sees none of the stock-changing shortcuts.
     *
     * @return array<Action|ActionGroup>
     */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('barcodeWorkbench')
                ->label(__('admin.resources.barcode_workbench'))
                ->icon(Heroicon::OutlinedQrCode)
                ->url(BarcodeWorkbench::getUrl())
                ->visible(fn (): bool => BarcodeWorkbench::canAccess()),
            Action::make('newTransfer')
                ->label(__('admin.inventory.operation.actions.create_internal_transfer'))
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->color('gray')
                ->url(fn (): string => InventoryOperationResource::getUrl('create', ['operation_type' => OperationType::InternalTransfer->value]))
                ->visible(fn (): bool => InventoryOperationResource::canCreateOperationType(OperationType::InternalTransfer)),
            ActionGroup::make([
                Action::make('newAdjustment')
                    ->label(__('admin.resources.adjustments'))
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->url(fn (): string => AdjustmentResource::getUrl('create'))
                    ->visible(fn (): bool => AdjustmentResource::canCreate()),
                Action::make('newCount')
                    ->label(__('admin.resources.inventory_counts'))
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->url(fn (): string => InventoryCountResource::getUrl('create'))
                    ->visible(fn (): bool => InventoryCountResource::canCreate()),
            ])->label(__('admin.inventory.workspace.more_actions'))->button()->color('gray'),
            ...parent::getHeaderActions(),
        ];
    }

    /** @return array<Select> */
    #[\Override]
    protected function moduleFilters(): array
    {
        return [
            Select::make('warehouseId')
                ->label(__('admin.inventory.stock.warehouse_name'))
                ->searchable()
                ->native(false)
                ->options(fn (): array => Warehouse::query()->orderBy('name')->pluck('name', 'id')->all()),
        ];
    }

    #[\Override]
    protected function getDashboardWidgets(): array
    {
        return [
            InventoryKeyMetrics::class,
            [InventoryMovementsTrend::class, InventoryStockValue::class],
            [InventoryLowStock::class, InventoryRecentMovements::class],
        ];
    }
}
