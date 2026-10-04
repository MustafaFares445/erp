<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InventoryAlertSeverity;
use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Filament\Resources\InventoryAlerts\InventoryAlertResource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAlert;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\ReplenishmentRequirement;
use App\Models\WarehouseReplenishmentPolicy;
use App\Services\Inventory\ReplenishmentTransferSuggestionService;
use App\Support\MoneyFormatter;
use App\Support\QuantityFormatter;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Inventory's headline cards, at most four: stock value and reorder need
 * for everyone with stock access, then the replenishment queue (or, without
 * replenishment access, unresolved alerts) and the documents awaiting
 * action. Everything narrows to the selected warehouse.
 */
final class InventoryKeyMetrics extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    use InteractsWithDashboardFilters;

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(InventoryPermission::StockView->value) ?? false;
    }

    #[\Override]
    protected function getStats(): array
    {
        $user = auth()->user();
        $stats = [$this->stockValueStat(), $this->needsReorderStat()];

        if ($user?->can(InventoryPermission::ReplenishmentPolicyView->value) ?? false) {
            $stats[] = $this->replenishmentStat();
        } elseif ($user?->can(InventoryPermission::AlertView->value) ?? false) {
            $stats[] = $this->unresolvedAlertsStat();
        }

        if (($user?->can(InventoryPermission::ReceiptView->value) ?? false)
            || ($user?->can(InventoryPermission::DeliveryView->value) ?? false)
            || ($user?->can(InventoryPermission::TransferView->value) ?? false)
            || ($user?->can(InventoryPermission::AdjustmentView->value) ?? false)) {
            $stats[] = $this->awaitingActionStat();
        }

        return $stats;
    }

    private function stockValueStat(): Stat
    {
        $total = $this->stocks()
            ->join('product_variants', 'product_variants.id', '=', 'inventory_stocks.product_variant_id')
            ->selectRaw('COALESCE(SUM(inventory_stocks.available_quantity * COALESCE(product_variants.cost_price, 0)), 0) as total')
            ->value('total');

        $activeSkus = $this->stocks()->where('on_hand_quantity', '>', 0)->distinct()->count('product_variant_id');
        $warehouses = $this->stocks()->where('on_hand_quantity', '>', 0)->distinct()->count('warehouse_id');

        return Stat::make(__('admin.inventory.dashboard.stock_value'), MoneyFormatter::formatAmount(is_numeric($total) ? $total : 0))
            ->description(__('dashboards.inventory.kpis.active_skus', ['skus' => $activeSkus, 'warehouses' => $warehouses]))
            ->icon(Heroicon::OutlinedBanknotes)
            ->url(StockLevelResource::getUrl('index'));
    }

    private function needsReorderStat(): Stat
    {
        $reorderQuery = $this->stocks()->where(function (Builder $query): void {
            $query->where('available_quantity', '<=', 0)
                ->orWhereExists(WarehouseReplenishmentPolicy::breachedSubquery());
        });

        $needsReorder = (clone $reorderQuery)->count();
        $outOfStock = (clone $reorderQuery)->where('available_quantity', '<=', 0)->count();

        return Stat::make(__('admin.inventory.dashboard.needs_reorder'), (string) $needsReorder)
            ->description(__('admin.inventory.dashboard.needs_reorder_description', ['count' => $outOfStock]))
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color($needsReorder > 0 ? 'danger' : 'success')
            ->url(StockLevelResource::getUrl('index'));
    }

    /**
     * Open requirements and the internal transfers that could cover them —
     * the purchasing dashboard shows only the residual external need.
     */
    private function replenishmentStat(): Stat
    {
        $requirements = ReplenishmentRequirement::query()
            ->active()
            ->when($this->dashboardFilter('warehouseId'), static fn (Builder $query, int $warehouseId): Builder => $query->where('warehouse_id', $warehouseId))
            ->get();

        $uncovered = $requirements->sum(
            static fn (ReplenishmentRequirement $requirement): float => $requirement->remainingUncoveredQuantity(),
        );

        $service = app(ReplenishmentTransferSuggestionService::class);
        $suggestions = 0;
        $suggestedQuantity = 0.0;
        $suggestionsByRequirement = $service->suggestMany($requirements);

        foreach ($requirements as $requirement) {
            $requirementId = $requirement->getKey();

            if (! is_numeric($requirementId)) {
                continue;
            }

            foreach ($suggestionsByRequirement[(int) $requirementId] ?? [] as $suggestion) {
                $suggestions++;
                $suggestedQuantity += $suggestion->suggestedBaseQuantity;
            }
        }

        return Stat::make(__('replenishment.open_requirements'), (string) $requirements->count())
            ->description(__('dashboards.inventory.kpis.replenishment_detail', [
                'quantity' => QuantityFormatter::display($uncovered),
                'suggestions' => $suggestions,
                'transferable' => QuantityFormatter::display($suggestedQuantity),
            ]))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color($requirements->isNotEmpty() ? 'warning' : 'success');
    }

    private function unresolvedAlertsStat(): Stat
    {
        $unresolvedQuery = InventoryAlert::query()
            ->whereNull('resolved_at')
            ->whereIn('severity', [InventoryAlertSeverity::Critical->value, InventoryAlertSeverity::Warning->value]);

        $unresolved = (clone $unresolvedQuery)->count();
        $critical = (clone $unresolvedQuery)->where('severity', InventoryAlertSeverity::Critical->value)->count();

        return Stat::make(__('admin.inventory.dashboard.unresolved_alerts'), (string) $unresolved)
            ->description(__('admin.inventory.dashboard.unresolved_alerts_description', ['count' => $critical]))
            ->icon(Heroicon::OutlinedBellAlert)
            ->color($critical > 0 ? 'danger' : ($unresolved > 0 ? 'warning' : 'success'))
            ->url(InventoryAlertResource::getUrl('index'));
    }

    private function awaitingActionStat(): Stat
    {
        $warehouseId = $this->dashboardFilter('warehouseId');

        $operations = InventoryOperation::query()
            ->whereNotIn('stage', [OperationStage::Done->value, OperationStage::Canceled->value])
            ->when($warehouseId, static fn (Builder $query, int $id): Builder => $query->where(
                static fn (Builder $query): Builder => $query->where('source_warehouse_id', $id)->orWhere('destination_warehouse_id', $id),
            ));

        $transfers = (clone $operations)->where('operation_type', OperationType::InternalTransfer->value)->count();
        $draftAdjustments = InventoryAdjustment::query()
            ->where('status', 'draft')
            ->when($warehouseId, static fn (Builder $query, int $id): Builder => $query->where('warehouse_id', $id))
            ->count();
        $total = $operations->count() + $draftAdjustments;

        return Stat::make(__('admin.inventory.dashboard.awaiting_action'), (string) $total)
            ->description(__('dashboards.inventory.kpis.awaiting_detail', ['adjustments' => $draftAdjustments, 'transfers' => $transfers]))
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->color($total > 0 ? 'warning' : 'success')
            ->url(InventoryOperationResource::getUrl('index'));
    }

    /** @return Builder<InventoryStock> */
    private function stocks(): Builder
    {
        return InventoryStock::query()
            ->when($this->dashboardFilter('warehouseId'), static fn (Builder $query, int $warehouseId): Builder => $query->where('inventory_stocks.warehouse_id', $warehouseId));
    }
}
