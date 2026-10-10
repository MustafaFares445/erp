<?php

declare(strict_types=1);

namespace App\Services\Inventory\Diagnostics;

use App\Data\Inventory\InventoryDiagnosis;
use App\Data\Inventory\InventoryDiagnosticAction;
use App\Data\Inventory\InventoryDiagnosticFinding;
use App\Enums\InventoryCountStatus;
use App\Enums\InventoryDiagnosticCategory as Category;
use App\Enums\InventoryDiagnosticSeverity as Severity;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\StockCondition;
use App\Filament\Resources\InventoryAlerts\InventoryAlertResource;
use App\Filament\Resources\InventoryCounts\InventoryCountResource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Models\InventoryAlert;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryCount;
use App\Models\InventoryLotBalance;
use App\Models\InventoryStock;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Warehouse-level roll-up: counts the item balances and open documents that need attention and
 * points to the filtered list or, when only one record is affected, straight to that record.
 * Every rule is one aggregate query scoped to this warehouse.
 */
final readonly class WarehouseDiagnoser implements InventoryRecordDiagnoser
{
    use BuildsInventoryFindings;

    private const int SampleSize = 5;

    public function supports(Model $record): bool
    {
        return $record instanceof Warehouse;
    }

    public function diagnose(Model $record): InventoryDiagnosis
    {
        assert($record instanceof Warehouse);

        $findings = [];

        if (! $record->is_active) {
            $findings[] = $this->finding('warehouse.inactive', Category::Workflow, Severity::Blocker,
                action: $this->editLink($record));
        } elseif ($record->latitude === null || $record->longitude === null) {
            $findings[] = $this->finding('warehouse.coordinates_missing', Category::DataQuality, Severity::Notice,
                action: $this->editLink($record));
        }

        $warehouseId = (int) $record->getKey();
        $stocks = fn (): Builder => InventoryStock::query()->where('warehouse_id', $warehouseId);

        if (! $stocks()->exists()) {
            $findings[] = $this->finding('warehouse.empty', Category::Availability, Severity::Notice);
        }

        $findings[] = $this->stockFinding(
            'warehouse.inconsistent', Category::DataQuality, Severity::Blocker,
            $stocks()->where(fn (Builder $query): Builder => $query
                ->whereColumn('reserved_quantity', '>', 'on_hand_quantity')
                ->orWhere('on_hand_quantity', '<', 0)),
            $warehouseId,
        );
        $findings[] = $this->stockFinding(
            'warehouse.unavailable', Category::Availability, Severity::Warning,
            $stocks()->where('on_hand_quantity', '>', 0)->where('available_quantity', '<=', 0),
            $warehouseId,
        );
        $findings[] = $this->stockFinding(
            'warehouse.below_minimum', Category::Replenishment, Severity::Warning,
            $stocks()->whereExists(WarehouseReplenishmentPolicy::breachedSubquery()),
            $warehouseId,
            ['low_stock' => ['isActive' => true]],
        );
        $findings[] = $this->stockFinding(
            'warehouse.quarantine', Category::Condition, Severity::Warning,
            $stocks()->whereIn('product_variant_id', InventoryConditionBalance::query()
                ->select('product_variant_id')
                ->where('warehouse_id', $warehouseId)
                ->where('stock_condition', StockCondition::Quarantine->value)
                ->where('on_hand_base_quantity', '>', 0)),
            $warehouseId,
        );

        $expiredLots = InventoryLotBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->where('stock_condition', StockCondition::Saleable->value)
            ->where('on_hand_base_quantity', '>', 0)
            ->whereHas('lot', fn (Builder $query): Builder => $query->whereNotNull('expires_at')->where('expires_at', '<', today()))
            ->distinct()
            ->count('inventory_lot_id');

        if ($expiredLots > 0) {
            $findings[] = $this->finding('warehouse.expired_lots', Category::Expiry, Severity::Warning, ['count' => $expiredLots],
                action: $this->stockIndexLink($warehouseId));
        }

        $alerts = InventoryAlert::query()
            ->whereNull('resolved_at')
            ->where('subject_type', InventoryStock::class)
            ->whereIn('subject_id', InventoryStock::query()->select('id')->where('warehouse_id', $warehouseId))
            ->count();

        if ($alerts > 0) {
            $findings[] = $this->finding('warehouse.alerts', Category::DataQuality, Severity::Warning, ['count' => $alerts],
                action: $this->indexLink(InventoryAlertResource::class, 'view_alerts', ['tableFilters' => ['active' => ['value' => true]]]));
        }

        $waiting = $record->sourceOperations()
            ->where('operation_type', OperationType::Delivery->value)
            ->where('stage', OperationStage::Waiting->value)
            ->count();

        if ($waiting > 0) {
            $findings[] = $this->finding('warehouse.waiting_deliveries', Category::Delivery, Severity::Warning, ['count' => $waiting],
                action: InventoryDiagnosticAction::link(
                    $this->actionLabel('view_waiting_deliveries'),
                    InventoryOperationResource::getUrl('deliveries'),
                    InventoryOperationResource::canViewAny(),
                ));
        }

        $transfers = $record->destinationOperations()
            ->where('operation_type', OperationType::InternalTransfer->value)
            ->whereIn('stage', [OperationStage::InTransit->value, OperationStage::PartiallyReceived->value])
            ->count();

        if ($transfers > 0) {
            $findings[] = $this->finding('warehouse.inbound_transfers', Category::Transfer, Severity::Notice, ['count' => $transfers],
                action: InventoryDiagnosticAction::link(
                    $this->actionLabel('view_transfers'),
                    InventoryOperationResource::getUrl('transfers'),
                    InventoryOperationResource::canViewAny(),
                ));
        }

        $counts = InventoryCount::query()
            ->where('warehouse_id', $warehouseId)
            ->where('status', InventoryCountStatus::PendingReview->value)
            ->count();

        if ($counts > 0) {
            $findings[] = $this->finding('warehouse.counts_pending', Category::Count, Severity::Warning, ['count' => $counts],
                action: $this->indexLink(InventoryCountResource::class, 'view_counts'));
        }

        return new InventoryDiagnosis(
            array_values(array_filter($findings)),
            checks: [
                $this->check('warehouse_status'),
                $this->check('warehouse_balances'),
                $this->check('warehouse_documents'),
                $this->check('warehouse_alerts'),
            ],
            limits: [
                $this->limit('warehouse_scope'),
                $this->limit('recorded_balances'),
            ],
        );
    }

    /**
     * One finding for a set of item balances: counts them, quotes a sample of SKUs and links to the
     * single affected balance or to the warehouse's filtered stock list.
     *
     * @param  Builder<InventoryStock>  $query
     * @param  array<string, mixed>  $extraFilters
     */
    private function stockFinding(
        string $code,
        Category $category,
        Severity $severity,
        Builder $query,
        int $warehouseId,
        array $extraFilters = [],
    ): ?InventoryDiagnosticFinding {
        $count = (clone $query)->count();

        if ($count === 0) {
            return null;
        }

        $sample = $query->with('productVariant:id,sku')->orderBy('id')->limit(self::SampleSize)->get();
        $facts = $sample->map(fn (InventoryStock $stock): string => $this->fact('stock_sample', [
            'sku' => (string) $stock->productVariant?->sku,
            'on_hand' => $this->quantity($stock->on_hand_quantity),
            'reserved' => $this->quantity($stock->reserved_quantity),
            'available' => $this->quantity($stock->available_quantity),
        ]))->values()->all();

        if ($count > self::SampleSize) {
            $facts[] = $this->fact('and_more', ['count' => $count - self::SampleSize]);
        }

        /** @var InventoryStock $first */
        $first = $sample->first();

        return $this->finding($code, $category, $severity, ['count' => $count], $facts,
            $count === 1
                ? $this->viewLink(StockLevelResource::class, $first, 'open_stock_level')
                : $this->stockIndexLink($warehouseId, $extraFilters),
        );
    }

    /**
     * @param  array<string, mixed>  $extraFilters
     */
    private function stockIndexLink(int $warehouseId, array $extraFilters = []): InventoryDiagnosticAction
    {
        return $this->indexLink(StockLevelResource::class, 'view_stock', [
            'tableFilters' => ['warehouse_id' => ['value' => $warehouseId], ...$extraFilters],
        ]);
    }

    private function editLink(Warehouse $warehouse): InventoryDiagnosticAction
    {
        return InventoryDiagnosticAction::link(
            $this->actionLabel('edit_warehouse'),
            WarehouseResource::getUrl('edit', ['record' => $warehouse]),
            WarehouseResource::canEdit($warehouse),
        );
    }
}
