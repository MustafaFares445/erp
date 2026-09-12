<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InventoryAlertSeverity;
use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Filament\Resources\InventoryAlerts\InventoryAlertResource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Models\InventoryAlert;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\ReplenishmentRequirement;
use App\Models\WarehouseReplenishmentPolicy;
use App\Services\Inventory\ReplenishmentTransferSuggestionService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

final class InventoryKeyMetrics extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(InventoryPermission::StockView->value) ?? false;
    }

    #[\Override]
    protected function getStats(): array
    {
        $user = auth()->user();
        $stats = [$this->stockValueStat(), $this->activeSkusStat(), $this->needsReorderStat()];

        if ($user?->can(InventoryPermission::ReplenishmentPolicyView->value) ?? false) {
            $stats[] = $this->openReplenishmentRequirementsStat();
            $stats[] = $this->transferSuggestionsStat();
        }

        if ($user?->can(InventoryPermission::AlertView->value) ?? false) {
            $stats[] = $this->unresolvedAlertsStat();
        }

        if (($user?->can(InventoryPermission::ReceiptView->value) ?? false)
            || ($user?->can(InventoryPermission::DeliveryView->value) ?? false)
            || ($user?->can(InventoryPermission::TransferView->value) ?? false)) {
            $stats[] = $this->awaitingActionStat();
        }

        return $stats;
    }

    private function stockValueStat(): Stat
    {
        $total = InventoryStock::query()
            ->join('product_variants', 'product_variants.id', '=', 'inventory_stocks.product_variant_id')
            ->selectRaw('COALESCE(SUM(inventory_stocks.available_quantity * COALESCE(product_variants.cost_price, 0)), 0) as total')
            ->value('total');

        return Stat::make(__('admin.inventory.dashboard.stock_value'), number_format(is_numeric($total) ? (float) $total : 0.0, 2))
            ->icon(Heroicon::OutlinedCurrencyDollar)
            ->color('success');
    }

    private function activeSkusStat(): Stat
    {
        $activeSkus = InventoryStock::query()->where('on_hand_quantity', '>', 0)->distinct()->count('product_variant_id');
        $warehouses = InventoryStock::query()->where('on_hand_quantity', '>', 0)->distinct()->count('warehouse_id');

        return Stat::make(__('admin.inventory.dashboard.active_skus'), (string) $activeSkus)
            ->description(__('admin.inventory.dashboard.active_skus_description', ['count' => $warehouses]))
            ->icon(Heroicon::OutlinedCube)
            ->color('gray');
    }

    private function needsReorderStat(): Stat
    {
        $reorderQuery = InventoryStock::query()->where(function (Builder $query): void {
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

    private function openReplenishmentRequirementsStat(): Stat
    {
        $requirements = ReplenishmentRequirement::query()->active()->get();
        $uncovered = $requirements->sum(
            static fn (ReplenishmentRequirement $requirement): float => $requirement->remainingUncoveredQuantity(),
        );

        return Stat::make(__('replenishment.open_requirements'), (string) $requirements->count())
            ->description(__('replenishment.open_requirements_description', [
                'quantity' => number_format((float) $uncovered, 6),
            ]))
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->color($requirements->isNotEmpty() ? 'warning' : 'success');
    }

    private function transferSuggestionsStat(): Stat
    {
        $service = app(ReplenishmentTransferSuggestionService::class);
        $count = 0;
        $quantity = 0.0;

        foreach (ReplenishmentRequirement::query()->active()->get() as $requirement) {
            foreach ($service->suggest($requirement) as $suggestion) {
                $count++;
                $quantity += $suggestion->suggestedBaseQuantity;
            }
        }

        return Stat::make(__('replenishment.transfer_suggestions'), (string) $count)
            ->description(__('replenishment.transfer_suggestions_description', [
                'quantity' => number_format($quantity, 6),
            ]))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color($count > 0 ? 'info' : 'gray');
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
        $awaiting = InventoryOperation::query()
            ->whereNotIn('stage', [OperationStage::Done->value, OperationStage::Canceled->value])
            ->count();

        return Stat::make(__('admin.inventory.dashboard.awaiting_action'), (string) $awaiting)
            ->description(__('admin.inventory.dashboard.awaiting_action_description'))
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->color('gray')
            ->url(InventoryOperationResource::getUrl('index'));
    }
}
