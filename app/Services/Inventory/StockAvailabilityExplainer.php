<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\InventoryConditionChangeStatus;
use App\Enums\InventoryConditionChangeType;
use App\Enums\MovementType;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\ReservationStatus;
use App\Enums\StockCondition;
use App\Filament\Resources\InventoryConditionChanges\InventoryConditionChangeResource;
use App\Filament\Resources\InventoryLots\InventoryLotResource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\InventoryReservations\InventoryReservationResource;
use App\Models\InventoryConditionChange;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use LogicException;

/**
 * "Why is X unavailable" for one variant/warehouse balance (WP-3.2, GAP-WL-05).
 *
 * `on_hand_quantity` and `available_quantity` on {@see InventoryStock} are
 * already correct; what is missing is the drill-through from the gap between
 * them to the documents holding it. By construction (enforced by
 * {@see InventoryPostingService::reconcileAvailable()}),
 * that gap always equals the sum of the saleable-reserved, quarantine
 * on-hand, and damaged on-hand condition balances, so the three named causes
 * below sum to the gap exactly with no residual. `inTransit` and `expired`
 * are surfaced as additional read-only context: neither changes this
 * warehouse's own on-hand/available numbers (in-transit stock has not yet
 * arrived here; an expired lot is still counted as saleable on-hand), so
 * they are reported separately rather than folded into `causes`.
 *
 * Entirely read-only: no method here writes a movement, balance, or
 * document. Every "action" entry only names an action that already exists
 * elsewhere and links to where it can be performed.
 */
final readonly class StockAvailabilityExplainer
{
    /**
     * Null-safe entry point for Filament callers, which only hold an
     * {@see InventoryStock} row whose `productVariant`/`warehouse`
     * relations are typed nullable even though both are required, non-null
     * foreign keys in practice. Falls back to an empty explanation (no
     * causes) for the practically-impossible case either relation failed
     * to load, rather than throwing out of a read-only view.
     *
     * @return array{
     *     on_hand: float,
     *     available: float,
     *     gap: float,
     *     causes: list<array{
     *         cause: string,
     *         label: string,
     *         quantity: float,
     *         documents: list<array{
     *             type: string,
     *             label: string,
     *             url: string|null,
     *             quantity: float|null,
     *             expires_at: string|null,
     *             holder: string|null,
     *         }>,
     *         action: array{label: string, url: string}|null,
     *     }>,
     *     in_transit: array{
     *         quantity: float,
     *         operations: list<array{label: string, url: string|null, quantity: float}>,
     *     },
     *     expired: array{
     *         quantity: float,
     *         lots: list<array{label: string, url: string|null, quantity: float, expires_at: string|null}>,
     *     },
     * }
     */
    public function explainStock(InventoryStock $stock): array
    {
        $variant = $stock->productVariant;
        $warehouse = $stock->warehouse;

        if (! $variant instanceof ProductVariant || ! $warehouse instanceof Warehouse) {
            return [
                'on_hand' => (float) $stock->on_hand_quantity,
                'available' => (float) $stock->available_quantity,
                'gap' => 0.0,
                'causes' => [],
                'in_transit' => ['quantity' => 0.0, 'operations' => []],
                'expired' => ['quantity' => 0.0, 'lots' => []],
            ];
        }

        return $this->explain($variant, $warehouse);
    }

    /**
     * @return array{
     *     on_hand: float,
     *     available: float,
     *     gap: float,
     *     causes: list<array{
     *         cause: string,
     *         label: string,
     *         quantity: float,
     *         documents: list<array{
     *             type: string,
     *             label: string,
     *             url: string|null,
     *             quantity: float|null,
     *             expires_at: string|null,
     *             holder: string|null,
     *         }>,
     *         action: array{label: string, url: string}|null,
     *     }>,
     *     in_transit: array{
     *         quantity: float,
     *         operations: list<array{label: string, url: string|null, quantity: float}>,
     *     },
     *     expired: array{
     *         quantity: float,
     *         lots: list<array{label: string, url: string|null, quantity: float, expires_at: string|null}>,
     *     },
     * }
     */
    public function explain(ProductVariant $variant, Warehouse $warehouse): array
    {
        $stock = InventoryStock::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->first();

        $onHand = $stock instanceof InventoryStock ? (float) $stock->on_hand_quantity : 0.0;
        $available = $stock instanceof InventoryStock ? $stock->saleableAvailableQuantity() : 0.0;
        $gap = round(max(0.0, $onHand - $available), 6);

        $causes = [];

        $reservedQuantity = $stock instanceof InventoryStock
            ? $stock->conditionReservedQuantity(StockCondition::Saleable)
            : 0.0;

        if ($reservedQuantity > 0.0) {
            $causes[] = $this->reservedCause($variant, $warehouse, $reservedQuantity);
        }

        $quarantineQuantity = $stock instanceof InventoryStock
            ? $stock->conditionOnHandQuantity(StockCondition::Quarantine)
            : 0.0;

        if ($quarantineQuantity > 0.0) {
            $causes[] = $this->quarantineCause($variant, $warehouse, $quarantineQuantity);
        }

        $damagedQuantity = $stock instanceof InventoryStock
            ? $stock->conditionOnHandQuantity(StockCondition::Damaged)
            : 0.0;

        if ($damagedQuantity > 0.0) {
            $causes[] = $this->damagedCause($variant, $warehouse, $damagedQuantity);
        }

        return [
            'on_hand' => $onHand,
            'available' => $available,
            'gap' => $gap,
            'causes' => $causes,
            'in_transit' => $this->inTransit($variant, $warehouse, $stock),
            'expired' => $this->expiredLots($variant, $warehouse),
        ];
    }

    /**
     * @return array{
     *     cause: string,
     *     label: string,
     *     quantity: float,
     *     documents: list<array{type: string, label: string, url: string|null, quantity: float|null, expires_at: string|null, holder: string|null}>,
     *     action: array{label: string, url: string}|null,
     * }
     */
    private function reservedCause(ProductVariant $variant, Warehouse $warehouse, float $quantity): array
    {
        $reservations = InventoryReservation::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->where('status', ReservationStatus::Active)
            ->with('sourceOperation.sourceDocument')
            ->orderBy('id')
            ->get();

        $documents = array_values($reservations->map(fn (InventoryReservation $reservation): array => [
            'type' => 'inventory_reservation',
            'label' => sprintf('Reservation #%d', $this->integerKey($reservation)),
            'url' => InventoryReservationResource::getUrl('view', ['record' => $reservation]),
            'quantity' => (float) $reservation->base_quantity,
            'expires_at' => $reservation->expires_at?->toDateTimeString(),
            'holder' => InventoryReservationResource::sourceDocumentLabel($reservation),
        ])->all());

        $action = $reservations->isNotEmpty() ? [
            'label' => __('admin.inventory.reservation.actions.release'),
            'url' => InventoryReservationResource::getUrl('view', ['record' => $reservations->first()]),
        ] : null;

        return [
            'cause' => 'reserved',
            'label' => __('admin.inventory.stock.reserved_quantity'),
            'quantity' => $quantity,
            'documents' => $documents,
            'action' => $action,
        ];
    }

    /**
     * @return array{
     *     cause: string,
     *     label: string,
     *     quantity: float,
     *     documents: list<array{type: string, label: string, url: string|null, quantity: float|null, expires_at: string|null, holder: string|null}>,
     *     action: array{label: string, url: string}|null,
     * }
     */
    private function quarantineCause(ProductVariant $variant, Warehouse $warehouse, float $quantity): array
    {
        $inboundMovements = InventoryMovement::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->where('movement_type', MovementType::Receipt->value)
            ->where('stock_condition_to', StockCondition::Quarantine->value)
            ->where('source_type', 'inventory_operation')
            ->orderBy('id')
            ->get()
            ->groupBy('source_id');

        /** @var Collection<int, Collection<int, InventoryMovement>> $inboundMovementsByOperation */
        $inboundMovementsByOperation = $inboundMovements;

        $documents = $inboundMovementsByOperation->map(function (Collection $movements, mixed $sourceId): array {
            $operationId = (int) $sourceId;
            $operation = InventoryOperation::query()->find($operationId);

            return [
                'type' => 'inventory_operation',
                'label' => $operation instanceof InventoryOperation
                    ? (string) ($operation->operation_number ?? 'Operation #'.$operationId)
                    : sprintf('Operation #%d', $operationId),
                'url' => $operation instanceof InventoryOperation
                    ? InventoryOperationResource::getUrl('view', ['record' => $operation])
                    : null,
                'quantity' => (float) $movements->sum(fn (InventoryMovement $movement): float => (float) $movement->base_quantity_delta),
                'expires_at' => null,
                'holder' => null,
            ];
        })->values()->all();

        $openDispositions = InventoryConditionChange::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->where('type', InventoryConditionChangeType::QuarantineDisposition)
            ->where('status', InventoryConditionChangeStatus::Draft)
            ->orderBy('id')
            ->get();

        foreach ($openDispositions as $disposition) {
            $documents[] = [
                'type' => 'inventory_condition_change',
                'label' => $disposition->document_number,
                'url' => InventoryConditionChangeResource::getUrl('view', ['record' => $disposition]),
                'quantity' => (float) $disposition->base_quantity,
                'expires_at' => null,
                'holder' => null,
            ];
        }

        $action = $openDispositions->isEmpty() ? [
            'label' => __('admin.inventory.condition_change.disposition_quarantine'),
            'url' => InventoryConditionChangeResource::getUrl('create', [
                'product_variant_id' => $variant->getKey(),
                'warehouse_id' => $warehouse->getKey(),
                'base_quantity' => number_format($quantity, 6, '.', ''),
            ]),
        ] : null;

        return [
            'cause' => 'quarantine',
            'label' => __('admin.inventory.stock.quarantine_quantity'),
            'quantity' => $quantity,
            'documents' => array_values($documents),
            'action' => $action,
        ];
    }

    /**
     * @return array{
     *     cause: string,
     *     label: string,
     *     quantity: float,
     *     documents: list<array{type: string, label: string, url: string|null, quantity: float|null, expires_at: string|null, holder: string|null}>,
     *     action: array{label: string, url: string}|null,
     * }
     */
    private function damagedCause(ProductVariant $variant, Warehouse $warehouse, float $quantity): array
    {
        $changes = InventoryConditionChange::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->where('type', InventoryConditionChangeType::Damage)
            ->where('status', InventoryConditionChangeStatus::Posted)
            ->where('condition_to', StockCondition::Damaged)
            ->orderBy('id')
            ->get();

        $documents = array_values($changes->map(fn (InventoryConditionChange $change): array => [
            'type' => 'inventory_condition_change',
            'label' => $change->document_number,
            'url' => InventoryConditionChangeResource::getUrl('view', ['record' => $change]),
            'quantity' => (float) $change->base_quantity,
            'expires_at' => null,
            'holder' => null,
        ])->all());

        return [
            'cause' => 'damaged',
            'label' => __('admin.inventory.stock.damaged_quantity'),
            'quantity' => $quantity,
            'documents' => $documents,
            'action' => [
                'label' => __('admin.inventory.damage.recover'),
                'url' => InventoryConditionChangeResource::getUrl('create', [
                    'type' => InventoryConditionChangeType::DamageRecovery->value,
                    'product_variant_id' => $variant->getKey(),
                    'warehouse_id' => $warehouse->getKey(),
                ]),
            ],
        ];
    }

    /**
     * @return array{quantity: float, operations: list<array{label: string, url: string|null, quantity: float}>}
     */
    private function inTransit(ProductVariant $variant, Warehouse $warehouse, ?InventoryStock $stock): array
    {
        $lines = InventoryOperationLine::query()
            ->where('product_variant_id', $variant->getKey())
            ->whereHas('operation', fn (Builder $query): Builder => $query
                ->where('operation_type', OperationType::InternalTransfer->value)
                ->where('destination_warehouse_id', $warehouse->getKey())
                ->whereIn('stage', [OperationStage::InTransit->value, OperationStage::PartiallyReceived->value]))
            ->with('operation')
            ->orderBy('id')
            ->get()
            ->groupBy('inventory_operation_id');

        $operations = $lines->map(function (Collection $operationLines): ?array {
            $operation = $operationLines->first()?->operation;

            if ($operation === null) {
                return null;
            }

            $quantity = (float) $operationLines->sum(fn (InventoryOperationLine $line): float => (float) $line->dispatched_base_quantity - (float) $line->received_base_quantity);

            if ($quantity <= 0.0) {
                return null;
            }

            return [
                'label' => (string) ($operation->operation_number ?? 'Operation #'.$this->integerKey($operation)),
                'url' => InventoryOperationResource::getUrl('view', ['record' => $operation]),
                'quantity' => $quantity,
            ];
        })->filter()->values()->all();

        $operations = array_values($operations);

        $quantity = $stock instanceof InventoryStock
            ? $stock->inTransitQuantity()
            : (float) array_sum(array_column($operations, 'quantity'));

        return [
            'quantity' => $quantity,
            'operations' => $operations,
        ];
    }

    /**
     * @return array{quantity: float, lots: list<array{label: string, url: string|null, quantity: float, expires_at: string|null}>}
     */
    private function expiredLots(ProductVariant $variant, Warehouse $warehouse): array
    {
        $lots = InventoryLot::query()
            ->where('product_variant_id', $variant->getKey())
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', today())
            ->whereHas('conditionBalances', fn (Builder $query): Builder => $query
                ->where('warehouse_id', $warehouse->getKey())
                ->where('stock_condition', StockCondition::Saleable->value)
                ->where('on_hand_base_quantity', '>', 0))
            ->with(['conditionBalances' => fn (Relation $query): Relation => $query
                ->where('warehouse_id', $warehouse->getKey())
                ->where('stock_condition', StockCondition::Saleable->value)])
            ->orderBy('expires_at')
            ->get();

        $warehouseId = $this->integerKey($warehouse);

        $entries = array_values($lots->map(function (InventoryLot $lot) use ($warehouseId): array {
            $quantity = $lot->conditionOnHandQuantity(StockCondition::Saleable, $warehouseId);

            return [
                'label' => (string) ($lot->lot_number ?? 'Lot #'.$this->integerKey($lot)),
                'url' => InventoryLotResource::getUrl('view', ['record' => $lot]),
                'quantity' => $quantity,
                'expires_at' => $lot->expires_at?->toDateString(),
            ];
        })->all());

        return [
            'quantity' => (float) array_sum(array_column($entries, 'quantity')),
            'lots' => $entries,
        ];
    }

    private function integerKey(Model $model): int
    {
        $key = $model->getKey();

        if (! is_int($key)) {
            throw new LogicException(sprintf('%s identifiers must be integers.', $model::class));
        }

        return $key;
    }
}
