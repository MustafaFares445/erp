<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryPostingCommand;
use App\Data\Inventory\TransferReceiptCommand;
use App\Data\Inventory\TransferReceiptLine;
use App\Enums\InventoryPermission;
use App\Enums\InventoryPostingBalanceMode;
use App\Enums\MovementType;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\TransferDiscrepancyDisposition;
use App\Events\InventoryOperationCompleted;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\Package;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Drives the {@see InventoryOperation} lifecycle behind every Receipt, Delivery and Internal
 * Transfer (US1, contracts/inventory-operations.md).
 *
 * The single invariant every method upholds (R-001, FR-003): a warehouse's on-hand balance
 * changes exactly when that warehouse's custody of the goods changes — never at Draft, Waiting,
 * or Ready. `markReady()` only reserves; `dispatch()`/`complete()` are the sole methods that ever
 * move `on_hand_quantity`.
 *
 * Every method locks the operation row (P-3) inside its own transaction and re-checks the stage
 * after acquiring the lock, so the loser of a concurrent confirmation sees the *current*
 * persisted stage rather than the one it read before the race (G-5).
 */
final readonly class InventoryOperationService
{
    public function __construct(
        private InventoryBalanceService $inventoryBalanceService,
        private InventoryAlertService $inventoryAlertService,
        private InventoryPostingService $inventoryPostingService,
        private InventoryLotService $inventoryLotService,
        private ProductTypeGuard $productTypeGuard,
        private QuantityNormalizer $quantityNormalizer,
    ) {}

    /**
     * Reserves outbound quantity and moves Draft/Waiting → Ready, or holds at Waiting when a
     * source warehouse cannot cover the required quantity (FR-004, FR-005).
     *
     * @throws DomainException
     */
    public function markReady(InventoryOperation $operation, ?User $actor = null): InventoryOperation
    {
        return DB::transaction(function () use ($operation, $actor): InventoryOperation {
            $locked = $this->lock($operation);
            $this->guardStageIsOneOf($locked, [OperationStage::Draft, OperationStage::Waiting]);

            $lines = $locked->lines()->with('unit')->orderBy('id')->lockForUpdate()->get();

            if ($lines->isEmpty()) {
                throw new DomainException(__('admin.inventory.operation.errors.no_lines'));
            }

            $variants = $this->lockVariants($lines);
            $this->assertTypeRulesHold($lines, $variants, $locked);
            $this->assertNoDuplicateSerials($locked, $lines);

            $this->snapshotOperationLines($lines, $variants, $locked);

            $sourceWarehouseId = $locked->source_warehouse_id;
            $packageWarehouseId = $locked->operation_type === OperationType::Receipt
                ? $locked->destination_warehouse_id
                : $sourceWarehouseId;

            if (is_int($packageWarehouseId)) {
                $this->assertPackagesBelongToWarehouse($lines, $packageWarehouseId);
            }

            if ($locked->operation_type === OperationType::Receipt || ! is_int($sourceWarehouseId)) {
                return $this->transitionTo($locked, OperationStage::Ready);
            }

            $shortfall = $this->firstInsufficientVariant($lines, $sourceWarehouseId);

            if ($shortfall !== null) {
                return $this->transitionTo($locked, OperationStage::Waiting);
            }

            $this->reserveLines($lines, $sourceWarehouseId);
            // Reserving the lot too, not just the aggregate balance, is what stops a second
            // operation committing the same batch of an expiry-tracked material.
            $this->reserveLots($lines, $variants, $sourceWarehouseId, $actor);

            return $this->transitionTo($locked, OperationStage::Ready);
        }, attempts: 5);
    }

    /**
     * Releases the source's custody of an internal transfer — the sole use of the `InTransit`
     * stage (V-03, R-001).
     *
     * @throws DomainException
     */
    public function dispatch(InventoryOperation $operation, User $actor): InventoryOperation
    {
        return DB::transaction(function () use ($operation, $actor): InventoryOperation {
            $locked = $this->lock($operation);

            if ($locked->operation_type !== OperationType::InternalTransfer) {
                throw new DomainException(__('admin.inventory.operation.errors.illegal_transition', ['from' => $locked->stage->value, 'to' => OperationStage::InTransit->value]));
            }

            $this->guardStageIsOneOf($locked, [OperationStage::Ready]);

            $sourceWarehouseId = $locked->source_warehouse_id;

            if (! is_int($sourceWarehouseId)) {
                throw new DomainException(__('admin.inventory.operation.errors.illegal_transition', ['from' => $locked->stage->value, 'to' => OperationStage::InTransit->value]));
            }

            $lines = $locked->lines()->orderBy('id')->lockForUpdate()->get();
            $this->dispatchTransferLines($lines, $sourceWarehouseId, $locked, $actor);
            // The source's custody ends here, so any device on this transfer must stop reading
            // `Available` at the old warehouse for the rest of the InTransit window — otherwise
            // it looks claimable by another operation until `complete()` lands it (G-5/R-001).
            $this->leaveSerializedUnits($lines, SerializedInventoryUnitStatus::InTransit);

            $locked->forceFill(['dispatched_at' => now()]);
            $dispatched = $this->transitionTo($locked, OperationStage::InTransit);
            $this->inventoryAlertService->syncTransferDiscrepancy($dispatched);

            return $dispatched;
        }, attempts: 5);
    }

    /**
     * The custody-taking transition: destination gains for a receipt or a transfer already
     * `InTransit`; source loses for a delivery (R-001).
     *
     * @throws DomainException
     */
    public function complete(InventoryOperation $operation, ?User $actor = null): InventoryOperation
    {
        if ($operation->operation_type === OperationType::InternalTransfer) {
            if (! $actor instanceof User) {
                throw new DomainException('An inventory operation actor is required to receive a transfer.');
            }

            if (! in_array($operation->stage, [OperationStage::InTransit, OperationStage::PartiallyReceived], true)) {
                throw new DomainException(__('admin.inventory.operation.errors.illegal_transition', [
                    'from' => $operation->stage->value,
                    'to' => OperationStage::InTransit->value,
                ]));
            }

            return $this->receiveTransfer($operation, $actor, $this->fullTransferReceipt($operation));
        }

        return DB::transaction(function () use ($operation, $actor): InventoryOperation {
            $locked = $this->lock($operation);
            $this->guardStageIsOneOf($locked, [OperationStage::Ready]);

            $lines = $locked->lines()->orderBy('id')->lockForUpdate()->get();

            match ($locked->operation_type) {
                OperationType::Receipt => $this->receiveLines($lines, $this->requireWarehouse($locked->destination_warehouse_id), $locked, $actor),
                OperationType::Delivery => $this->deliverLines($lines, $this->requireWarehouse($locked->source_warehouse_id), $locked, $actor),
                OperationType::InternalTransfer => throw new DomainException('Transfers must be received through the transfer receipt workflow.'),
            };

            $locked->forceFill(['completed_at' => now()]);

            $completed = $this->transitionTo($locked, OperationStage::Done);

            // Fired inside the transaction so anything reacting to a completed
            // operation commits with it or not at all. This carries no knowledge
            // of its listeners: Purchasing advances a purchase order's received
            // quantities from here, and Inventory stays unaware that Purchasing
            // exists (spec 017 research.md R-002).
            InventoryOperationCompleted::dispatch($completed, $actor);

            return $completed;
        }, attempts: 5);
    }

    /**
     * Receives the actual quantity physically counted at an internal transfer's destination.
     * The source already lost custody at dispatch; this method never creates destination stock
     * for anything that has not been explicitly received.
     *
     * @throws DomainException
     */
    public function receiveTransfer(
        InventoryOperation $operation,
        User $actor,
        TransferReceiptCommand $receipt,
    ): InventoryOperation {
        return DB::transaction(function () use ($operation, $actor, $receipt): InventoryOperation {
            $locked = $this->lock($operation);

            if ($locked->operation_type !== OperationType::InternalTransfer) {
                throw new DomainException(__('admin.inventory.operation.errors.illegal_transition', [
                    'from' => $locked->stage->value,
                    'to' => OperationStage::Done->value,
                ]));
            }

            $this->guardStageIsOneOf($locked, [OperationStage::InTransit, OperationStage::PartiallyReceived]);

            $lines = $locked->lines()->orderBy('id')->lockForUpdate()->get();
            $receiptLines = $this->receiptLinesByOperationLineId($receipt, $lines);
            $variants = $this->lockVariants($lines);
            $destinationWarehouseId = $this->requireWarehouse($locked->destination_warehouse_id);
            $sourceWarehouseId = $this->requireWarehouse($locked->source_warehouse_id);
            $commands = [];
            $receivedLineIds = [];
            $serialUpdates = [];
            $receivedAnything = false;

            foreach ($lines as $line) {
                if ($this->transferLineIsSettled($line)) {
                    continue;
                }

                $receiptLine = $receiptLines[$this->lineId($line)];
                $remainingBaseQuantity = $this->remainingTransferBaseQuantity($line);
                $receivedBaseQuantity = $this->receiptBaseQuantity($line, $receiptLine);

                if (bccomp($receivedBaseQuantity, $remainingBaseQuantity, 6) > 0) {
                    throw new DomainException('A transfer receipt cannot exceed the dispatched quantity.');
                }

                $hasDisposition = $receiptLine->discrepancyDisposition instanceof TransferDiscrepancyDisposition;

                if ($hasDisposition && bccomp($receivedBaseQuantity, $remainingBaseQuantity, 6) === 0) {
                    throw new DomainException('A fully received transfer line cannot have a discrepancy disposition.');
                }

                if ($hasDisposition && mb_trim((string) $receiptLine->discrepancyReason) === '') {
                    throw new DomainException('A transfer discrepancy disposition requires a reason.');
                }

                if (! $hasDisposition && mb_trim((string) $receiptLine->discrepancyReason) !== '') {
                    throw new DomainException('A transfer discrepancy reason requires a disposition.');
                }

                $newReceivedBaseQuantity = bcadd(
                    $this->receivedTransferBaseQuantity($line),
                    $receivedBaseQuantity,
                    6,
                );

                $variant = $variants[$line->product_variant_id] ?? null;

                if (! $variant instanceof ProductVariant) {
                    continue;
                }

                if (bccomp($receivedBaseQuantity, '0', 6) > 0) {
                    $destinationLot = $this->inventoryLotService->receiveTransfer(
                        $line,
                        $variant,
                        $destinationWarehouseId,
                        $receivedBaseQuantity,
                    );

                    $line->forceFill([
                        'received_base_quantity' => $newReceivedBaseQuantity,
                        'destination_inventory_lot_id' => $this->lotId($destinationLot),
                    ])->save();

                    $commands[] = $this->transferPostingCommand(
                        line: $line,
                        operation: $locked,
                        warehouseId: $destinationWarehouseId,
                        baseQuantityDelta: $receivedBaseQuantity,
                        transactionQuantity: $receiptLine->receivedTransactionQuantity,
                        inventoryLotId: $this->lotId($destinationLot),
                        idempotencySuffix: 'receive:'.$this->idempotencyQuantity($newReceivedBaseQuantity),
                        balanceMode: InventoryPostingBalanceMode::CreateIfMissing,
                        actor: $actor,
                    );

                    $receivedLineIds[$this->lineId($line)] = true;
                    $receivedAnything = true;

                    if ($line->serialized_inventory_unit_id !== null) {
                        $serialUpdates[$line->serialized_inventory_unit_id] = [
                            'status' => SerializedInventoryUnitStatus::Available,
                            'warehouse_id' => $destinationWarehouseId,
                        ];
                    }
                }

                if (! $hasDisposition) {
                    if (bccomp($receivedBaseQuantity, '0', 6) === 0) {
                        continue;
                    }

                    continue;
                }

                $unreceivedBaseQuantity = bcsub($remainingBaseQuantity, $receivedBaseQuantity, 6);
                $line->forceFill([
                    'received_base_quantity' => $newReceivedBaseQuantity,
                    'discrepancy_disposition' => $receiptLine->discrepancyDisposition,
                    'discrepancy_reason' => mb_trim((string) $receiptLine->discrepancyReason),
                ])->save();

                $this->resolveTransferDiscrepancy(
                    line: $line,
                    operation: $locked,
                    variant: $variant,
                    sourceWarehouseId: $sourceWarehouseId,
                    unreceivedBaseQuantity: $unreceivedBaseQuantity,
                    disposition: $receiptLine->discrepancyDisposition,
                    actor: $actor,
                    commands: $commands,
                    serialUpdates: $serialUpdates,
                );
            }

            if ($commands !== []) {
                $this->inventoryPostingService->postMany($commands);
            }

            $this->applyTransferSerialUpdates($serialUpdates);
            $this->movePackagesWithReceivedLineIds($lines, $receivedLineIds, $destinationWarehouseId);

            $unsettledLinesRemain = $lines->contains(
                fn (InventoryOperationLine $line): bool => ! $this->transferLineIsSettled($line->refresh()),
            );

            if ($unsettledLinesRemain) {
                if (! $receivedAnything) {
                    return $locked;
                }

                $locked->forceFill(['received_at' => now()]);

                $partiallyReceived = $this->transitionTo($locked, OperationStage::PartiallyReceived);
                $this->inventoryAlertService->syncTransferDiscrepancy($partiallyReceived);

                return $partiallyReceived;
            }

            $locked->forceFill([
                'received_at' => now(),
                'completed_at' => now(),
            ]);

            $completed = $this->transitionTo($locked, OperationStage::Done);
            $this->inventoryAlertService->syncTransferDiscrepancy($completed);
            InventoryOperationCompleted::dispatch($completed, $actor);

            return $completed;
        }, attempts: 5);
    }

    /**
     * Terminates an operation before it is Done. Releases any reservation; from `InTransit`,
     * additionally restores the source's on-hand with a compensating movement, so FR-009's
     * guarantee — cancelling never leaves an on-hand balance changed — holds even though the
     * source already lost custody at dispatch (spec Edge Cases).
     *
     * @throws DomainException
     */
    public function cancel(InventoryOperation $operation, User $actor, string $reason): InventoryOperation
    {
        return DB::transaction(function () use ($operation, $actor, $reason): InventoryOperation {
            $locked = $this->lock($operation);

            if ($locked->stage === OperationStage::Done) {
                throw new DomainException(__('admin.inventory.operation.errors.immutable'));
            }

            $this->guardStageIsOneOf($locked, [
                OperationStage::Draft, OperationStage::Waiting, OperationStage::Ready, OperationStage::InTransit, OperationStage::PartiallyReceived,
            ]);

            $lines = $locked->lines()->orderBy('id')->lockForUpdate()->get();

            if (in_array($locked->stage, [OperationStage::InTransit, OperationStage::PartiallyReceived], true)) {
                $sourceWarehouseId = $this->requireWarehouse($locked->source_warehouse_id);
                $variants = $this->lockVariants($lines);
                $commands = [];
                $serialUpdates = [];

                foreach ($lines as $line) {
                    if ($this->transferLineIsSettled($line)) {
                        continue;
                    }

                    $variant = $variants[$line->product_variant_id] ?? null;

                    if ($variant instanceof ProductVariant) {
                        $remainingBaseQuantity = $this->remainingTransferBaseQuantity($line);
                        $sourceLot = $this->inventoryLotService->restore($line, $variant, $remainingBaseQuantity);

                        $commands[] = $this->transferPostingCommand(
                            line: $line,
                            operation: $locked,
                            warehouseId: $sourceWarehouseId,
                            baseQuantityDelta: $remainingBaseQuantity,
                            transactionQuantity: $this->transactionQuantityForBase($line, $remainingBaseQuantity),
                            inventoryLotId: $this->lotId($sourceLot),
                            idempotencySuffix: 'cancel:'.$this->idempotencyQuantity($remainingBaseQuantity),
                            balanceMode: InventoryPostingBalanceMode::RequireExisting,
                            actor: $actor,
                        );
                    }

                    $line->forceFill([
                        'discrepancy_disposition' => TransferDiscrepancyDisposition::Cancelled,
                        'discrepancy_reason' => $reason,
                    ])->save();

                    if ($line->serialized_inventory_unit_id !== null) {
                        $serialUpdates[$line->serialized_inventory_unit_id] = [
                            'status' => SerializedInventoryUnitStatus::Available,
                            'warehouse_id' => $sourceWarehouseId,
                        ];
                    }
                }

                if ($commands !== []) {
                    $this->inventoryPostingService->postMany($commands);
                }

                $this->applyTransferSerialUpdates($serialUpdates);
            } elseif ($locked->stage === OperationStage::Ready) {
                $reservationWarehouseId = $this->reservationWarehouseId($locked);
                $this->releaseReservations($lines, $reservationWarehouseId);
                $this->releaseLots($lines, $this->lockVariants($lines));
            }

            $locked->forceFill(['canceled_at' => now(), 'notes' => mb_trim(($locked->notes ?? '').' '.$reason)]);

            $result = $this->transitionTo($locked, OperationStage::Canceled);
            $this->inventoryAlertService->syncTransferDiscrepancy($result);

            activity()
                ->performedOn($result)
                ->causedBy($actor)
                ->withChanges([
                    'old' => ['stage' => $locked->getOriginal('stage')],
                    'attributes' => ['stage' => OperationStage::Canceled->value, 'reason' => $reason],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('inventory.operation.canceled');

            return $result;
        }, attempts: 5);
    }

    /**
     * Read-only: the resulting balance per line if the next custody-changing transition were
     * confirmed right now (FR-010, SRS §5.1). Mutates nothing.
     *
     * @return list<array{product_variant_id: int, warehouse_id: int, before: float, after: float}>
     */
    public function previewEffect(InventoryOperation $operation): array
    {
        $isUpcomingReceive = $operation->operation_type !== OperationType::InternalTransfer
            ? $operation->operation_type === OperationType::Receipt
            : in_array($operation->stage, [OperationStage::InTransit, OperationStage::PartiallyReceived], true);

        $warehouseId = $isUpcomingReceive ? $operation->destination_warehouse_id : $operation->source_warehouse_id;

        if (! is_int($warehouseId)) {
            return [];
        }

        $preview = [];

        foreach ($operation->lines as $line) {
            $before = $this->decimal(InventoryStock::query()
                ->where('product_variant_id', $line->product_variant_id)
                ->where('warehouse_id', $warehouseId)
                ->value('on_hand_quantity'));

            $baseQuantity = $line->base_quantity ?? $line->quantity;
            $delta = $isUpcomingReceive
                ? (float) ($operation->operation_type === OperationType::InternalTransfer
                    ? $this->remainingTransferBaseQuantity($line)
                    : $baseQuantity)
                : -(float) $baseQuantity;

            $preview[] = [
                'product_variant_id' => $line->product_variant_id,
                'warehouse_id' => $warehouseId,
                'before' => $before,
                'after' => $before + $delta,
            ];
        }

        return $preview;
    }

    /**
     * Read-only: current available quantity for one variant/warehouse pair, or null when no
     * stock row exists yet. The write surfaces above are the only ones allowed to touch
     * {@see InventoryStock} from outside this service (contracts/inventory-operations.md P-2);
     * this is that same boundary's read counterpart for callers that need a live balance check
     * before an operation exists to preview against, such as the create-operation form.
     */
    public function availableQuantity(int $productVariantId, int $warehouseId): ?float
    {
        $availableQuantity = InventoryStock::query()
            ->where('product_variant_id', $productVariantId)
            ->where('warehouse_id', $warehouseId)
            ->value('available_quantity');

        return is_numeric($availableQuantity) ? (float) $availableQuantity : null;
    }

    /**
     * Read-only: available quantity and variant name for each of the given variants in one
     * warehouse, keyed by product_variant_id. Batched counterpart of
     * {@see self::availableQuantity()}, for callers checking several lines at once.
     *
     * @param  list<int>  $productVariantIds
     * @return array<int, array{available_quantity: float, variant_name: ?string}>
     */
    public function availableQuantitiesFor(array $productVariantIds, int $warehouseId): array
    {
        return InventoryStock::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_variant_id', $productVariantIds)
            ->with('productVariant:id,name')
            ->get()
            ->mapWithKeys(fn (InventoryStock $stock): array => [
                $stock->product_variant_id => [
                    'available_quantity' => (float) $stock->available_quantity,
                    'variant_name' => $stock->productVariant?->name,
                ],
            ])
            ->all();
    }

    /** @throws DomainException */
    private function lock(InventoryOperation $operation): InventoryOperation
    {
        /** @var InventoryOperation $locked */
        $locked = InventoryOperation::query()->lockForUpdate()->findOrFail($operation->getKey());

        return $locked;
    }

    /**
     * @param  list<OperationStage>  $allowed
     *
     * @throws DomainException
     */
    private function guardStageIsOneOf(InventoryOperation $operation, array $allowed): void
    {
        if (in_array($operation->stage, $allowed, true)) {
            return;
        }

        if ($operation->isTerminal()) {
            throw new DomainException(__('admin.inventory.operation.errors.already_processed'));
        }

        throw new DomainException(__('admin.inventory.operation.errors.illegal_transition', [
            'from' => $operation->stage->value,
            'to' => $allowed[0]->value,
        ]));
    }

    private function transitionTo(InventoryOperation $operation, OperationStage $stage): InventoryOperation
    {
        $operationNumber = $operation->operation_number;

        if ($operationNumber === null && in_array($stage, [OperationStage::Ready, OperationStage::InTransit, OperationStage::PartiallyReceived, OperationStage::Done], true)) {
            $operationNumber = $this->nextOperationNumber();
        }

        $operation->forceFill([
            'stage' => $stage,
            'operation_number' => $operationNumber,
        ]);
        $operation->save();

        return $operation->refresh();
    }

    /**
     * Locks every variant the operation touches, once, and asserts each is still sellable.
     *
     * Returns the variants keyed by id so the type-aware guards and the lot handling that follow
     * read the product type from an already-loaded relation instead of re-querying per line.
     *
     * @param  Collection<int, InventoryOperationLine>  $lines
     * @return array<int, ProductVariant>
     *
     * @throws DomainException
     */
    private function lockVariants(Collection $lines): array
    {
        $variants = [];

        foreach ($lines as $line) {
            $productVariantId = $line->product_variant_id;

            if (isset($variants[$productVariantId])) {
                continue;
            }

            /** @var ProductVariant $variant */
            $variant = ProductVariant::query()->with(['product.units', 'unit'])->lockForUpdate()->findOrFail($productVariantId);

            if (! $variant->isOperational()) {
                throw new DomainException(__('admin.inventory.operation.errors.inactive_variant'));
            }

            $variants[$productVariantId] = $variant;
        }

        return $variants;
    }

    /**
     * Every product-type rule a line must satisfy before the operation may leave Draft.
     *
     * Quantity precision used to be checked here directly against the unit; it now goes through
     * {@see ProductTypeGuard} so the type's own rule — a machine is never fractional — is
     * applied by the same call, in the same place, as the unit's.
     *
     * @param  Collection<int, InventoryOperationLine>  $lines
     * @param  array<int, ProductVariant>  $variants
     *
     * @throws DomainException
     */
    private function assertTypeRulesHold(Collection $lines, array $variants, InventoryOperation $operation): void
    {
        $isInbound = $operation->operation_type === OperationType::Receipt;

        foreach ($lines as $line) {
            $variant = $variants[$line->product_variant_id] ?? null;

            if (! $variant instanceof ProductVariant) {
                continue;
            }

            $this->productTypeGuard->assertUnitAllowed($variant, $line->unit);
            $this->productTypeGuard->assertQuantity($variant, (float) $line->quantity, $line->unit);
            $this->productTypeGuard->assertOperationLineSerial($variant, $line->serialized_inventory_unit_id, (float) $line->quantity);

            // A receipt line creates the lot, so it carries the expiry date; an outbound line
            // names an existing lot instead and is validated when that lot is drawn from.
            if ($isInbound) {
                $this->productTypeGuard->assertInboundExpiry($variant, $line->expires_at);
            }
        }
    }

    /**
     * Captures the transaction-UOM conversion before an operation reserves or changes custody.
     * All downstream balance, lot and ledger writes use this immutable base-quantity snapshot.
     *
     * @param  Collection<int, InventoryOperationLine>  $lines
     * @param  array<int, ProductVariant>  $variants
     */
    private function snapshotOperationLines(
        Collection $lines,
        array $variants,
        InventoryOperation $operation,
    ): void {
        foreach ($lines as $line) {
            $variant = $variants[$line->product_variant_id] ?? null;

            if (! $variant instanceof ProductVariant) {
                continue;
            }

            $normalized = $this->quantityNormalizer->normalize(
                $variant,
                $this->lineUnitId($line),
                (string) $line->quantity,
            );

            $attributes = [
                'transaction_quantity' => $normalized->transactionQuantity,
                'transaction_unit_id' => $normalized->transactionUnitId,
                'conversion_factor_snapshot' => $normalized->conversionFactorSnapshot,
                'base_quantity' => $normalized->baseQuantity,
            ];

            if ($operation->operation_type === OperationType::InternalTransfer) {
                $attributes = [
                    ...$attributes,
                    'received_base_quantity' => '0.000000',
                    'discrepancy_disposition' => null,
                    'discrepancy_reason' => null,
                ];
            }

            $line->forceFill($attributes)->save();
        }
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     *
     * @throws DomainException
     */
    private function assertNoDuplicateSerials(InventoryOperation $operation, Collection $lines): void
    {
        foreach ($lines->whereNotNull('serialized_inventory_unit_id') as $line) {
            $conflict = InventoryOperationLine::query()
                ->where('serialized_inventory_unit_id', $line->serialized_inventory_unit_id)
                ->where('inventory_operation_id', '!=', $operation->getKey())
                ->whereHas('operation', fn (Builder $query): Builder => $query->where('stage', '!=', OperationStage::Canceled->value))
                ->with('serializedUnit')
                ->first();

            if ($conflict instanceof InventoryOperationLine) {
                $serial = $conflict->serializedUnit->serial_number ?? (string) $conflict->serialized_inventory_unit_id;

                throw new DomainException(__('admin.inventory.operation.errors.duplicate_serial', ['serial' => $serial]));
            }
        }
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     */
    private function firstInsufficientVariant(Collection $lines, int $warehouseId): ?int
    {
        foreach ($lines->groupBy('product_variant_id') as $productVariantId => $variantLines) {
            if (! is_numeric($productVariantId)) {
                continue;
            }

            $stock = InventoryStock::query()
                ->where('product_variant_id', $productVariantId)
                ->where('warehouse_id', $warehouseId)
                ->first();

            $available = $stock instanceof InventoryStock ? (float) $stock->available_quantity : 0.0;

            if ($available < $this->decimal($this->baseQuantityForLines($variantLines))) {
                return (int) $productVariantId;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     */
    private function reserveLines(Collection $lines, int $warehouseId): void
    {
        foreach ($lines->groupBy('product_variant_id') as $productVariantId => $variantLines) {
            if (! is_numeric($productVariantId)) {
                continue;
            }

            $stock = InventoryStock::query()
                ->where('product_variant_id', $productVariantId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if ($stock instanceof InventoryStock) {
                $this->inventoryBalanceService->reserve($stock, $this->decimal($this->baseQuantityForLines($variantLines)));
            }
        }
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     *
     * @throws DomainException
     */
    private function assertPackagesBelongToWarehouse(Collection $lines, int $warehouseId): void
    {
        foreach ($lines as $line) {
            if (! Package::belongsToWarehouse($line->package_id, $warehouseId)) {
                throw new DomainException(__('admin.package.errors.warehouse_mismatch'));
            }
        }
    }

    /**
     * Consumes the reservation made at `markReady()` and posts the delivery's physical stock
     * change through the canonical posting boundary. The aggregate on-hand and reserved deltas
     * plus the immutable movement are committed together by InventoryPostingService.
     *
     * @param  Collection<int, InventoryOperationLine>  $lines
     */
    private function fulfillReservationAndLeave(Collection $lines, int $warehouseId, InventoryOperation $operation, ?User $actor): void
    {
        $variants = $this->lockVariants($lines);
        $commands = [];

        foreach ($lines as $line) {
            $variant = $variants[$line->product_variant_id] ?? null;

            if (! $variant instanceof ProductVariant) {
                continue;
            }

            // The lot mutation stays inside the operation transaction and is based on the same
            // normalized base quantity used by the aggregate posting. Phase 6 moves this
            // collaborator behind the posting boundary when lot balances are split from identity.
            $lot = $this->inventoryLotService->consume(
                $line,
                $variant,
                $warehouseId,
                $actor,
                $this->mayReleaseExpiredStock($actor),
            );

            $snapshot = $this->postingSnapshot($line);
            $baseQuantityDelta = bcsub('0', $snapshot['base_quantity'], 6);

            $commands[] = new InventoryPostingCommand(
                productVariantId: $this->lineVariantId($line),
                warehouseId: $warehouseId,
                onHandBaseQuantityDelta: $baseQuantityDelta,
                reservedBaseQuantityDelta: $baseQuantityDelta,
                damagedBaseQuantityDelta: '0',
                movementType: MovementType::Sale,
                movementBaseQuantityDelta: $baseQuantityDelta,
                sourceType: 'inventory_operation',
                sourceId: $this->operationId($operation),
                actorId: $this->actorId($actor),
                notes: $operation->notes,
                serializedInventoryUnitId: $line->serialized_inventory_unit_id,
                idempotencyKey: sprintf(
                    'inventory-operation-delivery:%d:%d',
                    $this->operationId($operation),
                    $this->lineId($line),
                ),
                balanceMode: InventoryPostingBalanceMode::RequireExisting,
                inventoryLotId: $this->lotId($lot),
                packageId: $line->package_id,
                sourceLineType: 'inventory_operation_line',
                sourceLineId: $this->lineId($line),
                transactionQuantity: $snapshot['transaction_quantity'],
                transactionUnitId: $snapshot['transaction_unit_id'],
                conversionFactorSnapshot: $snapshot['conversion_factor_snapshot'],
                baseQuantityDelta: $baseQuantityDelta,
            );
        }

        if ($commands !== []) {
            $this->inventoryPostingService->postMany($commands);
        }
    }

    /**
     * Posts the source-side leg of an internal transfer. Unlike delivery, the document carries
     * its UOM snapshots forward and keeps the source lot allocation for later destination
     * receiving or a compensating cancellation.
     *
     * @param  Collection<int, InventoryOperationLine>  $lines
     */
    private function dispatchTransferLines(Collection $lines, int $warehouseId, InventoryOperation $operation, User $actor): void
    {
        $variants = $this->lockVariants($lines);
        $commands = [];

        foreach ($lines as $line) {
            $variant = $variants[$line->product_variant_id] ?? null;

            if (! $variant instanceof ProductVariant) {
                continue;
            }

            $sourceLot = $this->inventoryLotService->consume(
                $line,
                $variant,
                $warehouseId,
                $actor,
                $this->mayReleaseExpiredStock($actor),
            );
            $baseQuantity = $this->transferBaseQuantity($line);

            $line->forceFill([
                'source_inventory_lot_id' => $this->lotId($sourceLot),
                'dispatched_base_quantity' => $baseQuantity,
                'received_base_quantity' => '0.000000',
            ])->save();

            $commands[] = $this->transferPostingCommand(
                line: $line,
                operation: $operation,
                warehouseId: $warehouseId,
                baseQuantityDelta: bcsub('0', $baseQuantity, 6),
                transactionQuantity: (string) $line->transaction_quantity,
                inventoryLotId: $this->lotId($sourceLot),
                idempotencySuffix: 'dispatch',
                balanceMode: InventoryPostingBalanceMode::RequireExisting,
                actor: $actor,
                reservedBaseQuantityDelta: bcsub('0', $baseQuantity, 6),
            );
        }

        $this->inventoryPostingService->postMany($commands);
    }

    /**
     * A delivery's custody-leaving step: the balance/lot/movement side is identical to an
     * internal transfer's dispatch, but a delivery has no receiving leg to ever land a device
     * back in `Available` — so any device on the line must be finalized as `Delivered` here, or
     * it would read `Available` at a warehouse it has permanently left (R-001).
     *
     * @param  Collection<int, InventoryOperationLine>  $lines
     */
    private function deliverLines(Collection $lines, int $warehouseId, InventoryOperation $operation, ?User $actor): void
    {
        $this->fulfillReservationAndLeave($lines, $warehouseId, $operation, $actor);
        $this->leaveSerializedUnits($lines, SerializedInventoryUnitStatus::Delivered);
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     */
    private function receiveLines(Collection $lines, int $warehouseId, InventoryOperation $operation, ?User $actor): void
    {
        if ($operation->operation_type !== OperationType::Receipt) {
            throw new DomainException('Only receipt operations may enter the receipt posting path.');
        }

        $this->receiveReceiptLines($lines, $warehouseId, $operation, $actor);
    }

    /**
     * Receipts are the first operation consumer of the canonical posting boundary. The draft
     * line keeps its commercial transaction quantity/unit, while the persisted snapshots and
     * the stock, lot and movement writes use its exact variant base quantity.
     *
     * @param  Collection<int, InventoryOperationLine>  $lines
     */
    private function receiveReceiptLines(Collection $lines, int $warehouseId, InventoryOperation $operation, ?User $actor): void
    {
        $variants = $this->lockVariants($lines);

        foreach ($lines as $line) {
            $variant = $variants[$line->product_variant_id] ?? null;

            if (! $variant instanceof ProductVariant) {
                continue;
            }

            $snapshot = $this->postingSnapshot($line);
            $lot = $this->inventoryLotService->receive($line, $variant, $warehouseId, $snapshot['base_quantity']);

            $result = $this->inventoryPostingService->post(new InventoryPostingCommand(
                productVariantId: $this->lineVariantId($line),
                warehouseId: $warehouseId,
                onHandBaseQuantityDelta: $snapshot['base_quantity'],
                reservedBaseQuantityDelta: '0',
                damagedBaseQuantityDelta: '0',
                movementType: MovementType::Receipt,
                movementBaseQuantityDelta: $snapshot['base_quantity'],
                sourceType: 'inventory_operation',
                sourceId: $this->operationId($operation),
                actorId: $this->actorId($actor),
                notes: $operation->notes,
                serializedInventoryUnitId: $line->serialized_inventory_unit_id,
                idempotencyKey: sprintf('inventory-operation-receipt:%d:%d', $this->operationId($operation), $this->lineId($line)),
                balanceMode: InventoryPostingBalanceMode::CreateIfMissing,
                inventoryLotId: $this->lotId($lot),
                packageId: $line->package_id,
                sourceLineType: 'inventory_operation_line',
                sourceLineId: $this->lineId($line),
                transactionQuantity: $snapshot['transaction_quantity'],
                transactionUnitId: $snapshot['transaction_unit_id'],
                conversionFactorSnapshot: $snapshot['conversion_factor_snapshot'],
                baseQuantityDelta: $snapshot['base_quantity'],
            ));

            if ($result->serializedUnit instanceof SerializedInventoryUnit) {
                $result->serializedUnit->forceFill([
                    'warehouse_id' => $warehouseId,
                    'status' => SerializedInventoryUnitStatus::Available,
                ])->save();
            }
        }
    }

    /**
     * Lands a device in the receiving warehouse: a fresh receipt promotes it from `Pending`, and
     * an internal transfer's destination leg just relocates one already `Available` elsewhere.
     * Without this, `serialized_inventory_unit_id` would be recorded on the line but the device
     * itself would never become selectable stock for a later outbound operation.
     */
    private function receiveSerializedUnit(int $serializedInventoryUnitId, int $warehouseId): void
    {
        $serializedUnit = SerializedInventoryUnit::query()->whereKey($serializedInventoryUnitId)->lockForUpdate()->first();

        if (! $serializedUnit instanceof SerializedInventoryUnit) {
            return;
        }

        $serializedUnit->forceFill([
            'warehouse_id' => $warehouseId,
            'status' => SerializedInventoryUnitStatus::Available,
        ])->save();
    }

    /**
     * Finalizes every device on these lines into a status that no longer reads `Available` at a
     * warehouse whose custody just changed — the counterpart to {@see self::receiveSerializedUnit()}
     * for the two transitions that take custody away rather than landing it.
     *
     * @param  Collection<int, InventoryOperationLine>  $lines
     */
    private function leaveSerializedUnits(Collection $lines, SerializedInventoryUnitStatus $status): void
    {
        foreach ($lines->whereNotNull('serialized_inventory_unit_id') as $line) {
            $serializedUnit = SerializedInventoryUnit::query()->whereKey($line->serialized_inventory_unit_id)->lockForUpdate()->first();

            $serializedUnit?->forceFill(['status' => $status])->save();
        }
    }

    /**
     * Holds each expiry-tracked line's quantity against the lot it names, alongside the
     * aggregate reservation {@see self::reserveLines()} makes.
     *
     * @param  Collection<int, InventoryOperationLine>  $lines
     * @param  array<int, ProductVariant>  $variants
     *
     * @throws DomainException
     */
    private function reserveLots(Collection $lines, array $variants, int $warehouseId, ?User $actor): void
    {
        foreach ($lines as $line) {
            $variant = $variants[$line->product_variant_id] ?? null;

            if ($variant instanceof ProductVariant) {
                $this->inventoryLotService->reserve($line, $variant, $warehouseId, $actor, $this->mayReleaseExpiredStock($actor));
            }
        }
    }

    /**
     * Returns each expiry-tracked line's reservation to its lot when an operation is cancelled
     * from Ready — the mirror of {@see self::reserveLots()}.
     *
     * @param  Collection<int, InventoryOperationLine>  $lines
     * @param  array<int, ProductVariant>  $variants
     */
    private function releaseLots(Collection $lines, array $variants): void
    {
        foreach ($lines as $line) {
            $variant = $variants[$line->product_variant_id] ?? null;

            if ($variant instanceof ProductVariant) {
                $this->inventoryLotService->release($line, $variant);
            }
        }
    }

    /**
     * Whether this actor may push expired goods through an outbound operation.
     *
     * A null actor — a scheduled or system-initiated transition with nobody to hold
     * accountable — never may, so the block stays in force by default rather than being
     * bypassed by the absence of a user.
     */
    private function mayReleaseExpiredStock(?User $actor): bool
    {
        return $actor?->can(InventoryPermission::ExpiredStockOverride->value) === true;
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     * @param  array<int, true>  $receivedLineIds
     */
    private function movePackagesWithReceivedLineIds(Collection $lines, array $receivedLineIds, int $warehouseId): void
    {
        foreach ($lines as $line) {
            if (! isset($receivedLineIds[$this->lineId($line)])) {
                continue;
            }

            if ($line->package_id === null) {
                continue;
            }

            $package = Package::query()->lockForUpdate()->find($line->package_id);

            if ($package instanceof Package) {
                $package->moveWithRecordedGoods($warehouseId);
            }
        }
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     */
    private function releaseReservations(Collection $lines, int $warehouseId): void
    {
        foreach ($lines->groupBy('product_variant_id') as $productVariantId => $variantLines) {
            if (! is_numeric($productVariantId)) {
                continue;
            }

            $stock = InventoryStock::query()
                ->where('product_variant_id', $productVariantId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if ($stock instanceof InventoryStock && (float) $stock->reserved_quantity > 0) {
                $toRelease = min((float) $stock->reserved_quantity, $this->decimal($this->baseQuantityForLines($variantLines)));
                $this->inventoryBalanceService->releaseReservation($stock, $toRelease);
            }
        }
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     * @return array<int, TransferReceiptLine>
     *
     * @throws DomainException
     */
    private function receiptLinesByOperationLineId(TransferReceiptCommand $receipt, Collection $lines): array
    {
        $unsettledLineIds = [];

        foreach ($lines as $line) {
            if (! $this->transferLineIsSettled($line)) {
                $unsettledLineIds[$this->lineId($line)] = true;
            }
        }

        if ($unsettledLineIds === []) {
            throw new DomainException('This transfer has no outstanding lines to receive.');
        }

        $receiptLines = [];

        foreach ($receipt->lines as $receiptLine) {
            if (! isset($unsettledLineIds[$receiptLine->operationLineId])) {
                throw new DomainException('A transfer receipt may contain only outstanding transfer lines.');
            }

            if (isset($receiptLines[$receiptLine->operationLineId])) {
                throw new DomainException('A transfer receipt cannot contain a line more than once.');
            }

            $receiptLines[$receiptLine->operationLineId] = $receiptLine;
        }

        if (count($receiptLines) !== count($unsettledLineIds)) {
            throw new DomainException('A transfer receipt must account for every outstanding transfer line.');
        }

        return $receiptLines;
    }

    /**
     * @return numeric-string
     *
     * @throws DomainException
     */
    private function receiptBaseQuantity(InventoryOperationLine $line, TransferReceiptLine $receiptLine): string
    {
        $transactionQuantity = $this->normalizedNonNegativeQuantity($receiptLine->receivedTransactionQuantity);
        $conversionFactor = $line->conversion_factor_snapshot;

        if (! is_string($conversionFactor)) {
            throw new DomainException('A dispatched transfer line requires a conversion snapshot.');
        }

        $normalizedConversionFactor = $this->normalizedNonNegativeQuantity($conversionFactor);

        if (bccomp($normalizedConversionFactor, '0', 6) <= 0) {
            throw new DomainException('A dispatched transfer line requires a conversion snapshot.');
        }

        return bcmul($transactionQuantity, $normalizedConversionFactor, 6);
    }

    /** @return numeric-string */
    private function normalizedNonNegativeQuantity(string $quantity): string
    {
        if (! is_numeric($quantity) || preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/D', $quantity) !== 1) {
            throw new DomainException('Transfer receipt quantities must be non-negative decimals with at most six places.');
        }

        return bcadd($quantity, '0', 6);
    }

    /** @return numeric-string */
    private function transferBaseQuantity(InventoryOperationLine $line): string
    {
        $baseQuantity = $line->base_quantity;

        if (! is_string($baseQuantity)) {
            throw new DomainException('A dispatched transfer line requires a positive normalized base quantity.');
        }

        $normalizedBaseQuantity = $this->normalizedNonNegativeQuantity($baseQuantity);

        if (bccomp($normalizedBaseQuantity, '0', 6) <= 0) {
            throw new DomainException('A dispatched transfer line requires a positive normalized base quantity.');
        }

        return $normalizedBaseQuantity;
    }

    /** @return numeric-string */
    private function receivedTransferBaseQuantity(InventoryOperationLine $line): string
    {
        return $this->normalizedNonNegativeQuantity((string) ($line->received_base_quantity ?? '0'));
    }

    /** @return numeric-string */
    private function remainingTransferBaseQuantity(InventoryOperationLine $line): string
    {
        return bcsub($this->dispatchedTransferBaseQuantity($line), $this->receivedTransferBaseQuantity($line), 6);
    }

    /** @return numeric-string */
    private function dispatchedTransferBaseQuantity(InventoryOperationLine $line): string
    {
        $dispatchedBaseQuantity = $line->dispatched_base_quantity;

        if (! is_string($dispatchedBaseQuantity)) {
            throw new DomainException('An in-transit transfer line requires a dispatched base quantity.');
        }

        $normalizedDispatchedBaseQuantity = $this->normalizedNonNegativeQuantity($dispatchedBaseQuantity);

        if (bccomp($normalizedDispatchedBaseQuantity, '0', 6) <= 0) {
            throw new DomainException('An in-transit transfer line requires a dispatched base quantity.');
        }

        return $normalizedDispatchedBaseQuantity;
    }

    private function transferLineIsSettled(InventoryOperationLine $line): bool
    {
        if ($line->discrepancy_disposition instanceof TransferDiscrepancyDisposition) {
            return true;
        }

        return bccomp($this->receivedTransferBaseQuantity($line), $this->dispatchedTransferBaseQuantity($line), 6) >= 0;
    }

    /**
     * @param  list<InventoryPostingCommand>  &$commands
     * @param  array<int, array{status: SerializedInventoryUnitStatus, warehouse_id: int|null}>  &$serialUpdates
     */
    private function resolveTransferDiscrepancy(
        InventoryOperationLine $line,
        InventoryOperation $operation,
        ProductVariant $variant,
        int $sourceWarehouseId,
        string $unreceivedBaseQuantity,
        TransferDiscrepancyDisposition $disposition,
        User $actor,
        array &$commands,
        array &$serialUpdates,
    ): void {
        if ($disposition === TransferDiscrepancyDisposition::Cancelled) {
            $sourceLot = $this->inventoryLotService->restore($line, $variant, $unreceivedBaseQuantity);
            $commands[] = $this->transferPostingCommand(
                line: $line,
                operation: $operation,
                warehouseId: $sourceWarehouseId,
                baseQuantityDelta: $unreceivedBaseQuantity,
                transactionQuantity: $this->transactionQuantityForBase($line, $unreceivedBaseQuantity),
                inventoryLotId: $this->lotId($sourceLot),
                idempotencySuffix: 'cancel:'.$this->idempotencyQuantity($unreceivedBaseQuantity),
                balanceMode: InventoryPostingBalanceMode::RequireExisting,
                actor: $actor,
            );

            if ($line->serialized_inventory_unit_id !== null) {
                $serialUpdates[$line->serialized_inventory_unit_id] = [
                    'status' => SerializedInventoryUnitStatus::Available,
                    'warehouse_id' => $sourceWarehouseId,
                ];
            }

            return;
        }

        if ($line->serialized_inventory_unit_id === null) {
            return;
        }

        $serialUpdates[$line->serialized_inventory_unit_id] = [
            'status' => $disposition === TransferDiscrepancyDisposition::Damaged
                ? SerializedInventoryUnitStatus::Damaged
                : SerializedInventoryUnitStatus::Unknown,
            'warehouse_id' => null,
        ];
    }

    /**
     * @param  array<int, array{status: SerializedInventoryUnitStatus, warehouse_id: int|null}>  $serialUpdates
     */
    private function applyTransferSerialUpdates(array $serialUpdates): void
    {
        foreach ($serialUpdates as $serializedInventoryUnitId => $attributes) {
            $serializedUnit = SerializedInventoryUnit::query()->lockForUpdate()->find($serializedInventoryUnitId);

            $serializedUnit?->forceFill($attributes)->save();
        }
    }

    private function transferPostingCommand(
        InventoryOperationLine $line,
        InventoryOperation $operation,
        int $warehouseId,
        string $baseQuantityDelta,
        string $transactionQuantity,
        ?int $inventoryLotId,
        string $idempotencySuffix,
        InventoryPostingBalanceMode $balanceMode,
        User $actor,
        string $reservedBaseQuantityDelta = '0',
    ): InventoryPostingCommand {
        $conversionFactor = $line->conversion_factor_snapshot;
        $transactionUnitId = $line->transaction_unit_id;

        if (! is_string($conversionFactor) || ! is_int($transactionUnitId)) {
            throw new DomainException('A transfer posting requires complete transaction-UOM snapshots.');
        }

        return new InventoryPostingCommand(
            productVariantId: $this->lineVariantId($line),
            warehouseId: $warehouseId,
            onHandBaseQuantityDelta: $baseQuantityDelta,
            reservedBaseQuantityDelta: $reservedBaseQuantityDelta,
            damagedBaseQuantityDelta: '0',
            movementType: MovementType::Transfer,
            movementBaseQuantityDelta: $baseQuantityDelta,
            sourceType: 'inventory_operation',
            sourceId: $this->operationId($operation),
            actorId: $this->actorId($actor),
            notes: $operation->notes,
            serializedInventoryUnitId: $line->serialized_inventory_unit_id,
            idempotencyKey: sprintf(
                'inventory-operation-transfer:%d:%d:%s',
                $this->operationId($operation),
                $this->lineId($line),
                $idempotencySuffix,
            ),
            balanceMode: $balanceMode,
            inventoryLotId: $inventoryLotId,
            packageId: $line->package_id,
            sourceLineType: 'inventory_operation_line',
            sourceLineId: $this->lineId($line),
            transactionQuantity: $transactionQuantity,
            transactionUnitId: $transactionUnitId,
            conversionFactorSnapshot: $conversionFactor,
            baseQuantityDelta: $baseQuantityDelta,
        );
    }

    /**
     * @return array{
     *     transaction_quantity: numeric-string,
     *     transaction_unit_id: int,
     *     conversion_factor_snapshot: numeric-string,
     *     base_quantity: numeric-string
     * }
     */
    private function postingSnapshot(InventoryOperationLine $line): array
    {
        $transactionQuantity = $this->normalizedNonNegativeQuantity((string) $line->transaction_quantity);
        $conversionFactor = $this->normalizedNonNegativeQuantity((string) $line->conversion_factor_snapshot);
        $baseQuantity = $this->normalizedNonNegativeQuantity((string) $line->base_quantity);
        $transactionUnitId = $line->transaction_unit_id;

        if (
            bccomp($transactionQuantity, '0', 6) <= 0
            || bccomp($conversionFactor, '0', 6) <= 0
            || bccomp($baseQuantity, '0', 6) <= 0
            || ! is_int($transactionUnitId)
        ) {
            throw new DomainException('An inventory operation line requires a complete positive UOM snapshot before posting.');
        }

        return [
            'transaction_quantity' => $transactionQuantity,
            'transaction_unit_id' => $transactionUnitId,
            'conversion_factor_snapshot' => $conversionFactor,
            'base_quantity' => $baseQuantity,
        ];
    }

    /** @return numeric-string */
    private function transactionQuantityForBase(InventoryOperationLine $line, string $baseQuantity): string
    {
        $conversionFactor = $line->conversion_factor_snapshot;

        if (! is_string($conversionFactor)) {
            throw new DomainException('A transfer line requires a conversion snapshot.');
        }

        $normalizedConversionFactor = $this->normalizedNonNegativeQuantity($conversionFactor);

        if (bccomp($normalizedConversionFactor, '0', 6) <= 0) {
            throw new DomainException('A transfer line requires a conversion snapshot.');
        }

        return bcdiv($this->normalizedNonNegativeQuantity($baseQuantity), $normalizedConversionFactor, 6);
    }

    private function idempotencyQuantity(string $quantity): string
    {
        return str_replace('.', '_', $this->normalizedNonNegativeQuantity($quantity));
    }

    /** @return numeric-string */
    /** @param Collection<int, InventoryOperationLine> $lines */
    private function baseQuantityForLines(Collection $lines): string
    {
        $total = '0.000000';

        foreach ($lines as $line) {
            $baseQuantity = $line->base_quantity ?? $line->quantity;
            $total = bcadd($total, $this->normalizedNonNegativeQuantity((string) $baseQuantity), 6);
        }

        return $total;
    }

    private function fullTransferReceipt(InventoryOperation $operation): TransferReceiptCommand
    {
        $receiptLines = [];

        foreach ($operation->lines()->orderBy('id')->get() as $line) {
            if ($this->transferLineIsSettled($line)) {
                continue;
            }

            $remainingBaseQuantity = $this->remainingTransferBaseQuantity($line);
            $receiptLines[] = new TransferReceiptLine(
                operationLineId: $this->lineId($line),
                receivedTransactionQuantity: $this->transactionQuantityForBase($line, $remainingBaseQuantity),
            );
        }

        return new TransferReceiptCommand($receiptLines);
    }

    private function reservationWarehouseId(InventoryOperation $operation): int
    {
        $warehouseId = $operation->source_warehouse_id;

        return is_int($warehouseId) ? $warehouseId : 0;
    }

    private function requireWarehouse(?int $warehouseId): int
    {
        if (! is_int($warehouseId)) {
            throw new DomainException(__('admin.inventory.operation.errors.illegal_transition', ['from' => '', 'to' => '']));
        }

        return $warehouseId;
    }

    private function lineId(InventoryOperationLine $line): int
    {
        $key = $line->getKey();

        if (! is_int($key)) {
            throw new \LogicException('Inventory operation lines must use integer identifiers.');
        }

        return $key;
    }

    private function lineUnitId(InventoryOperationLine $line): int
    {
        return $line->unit_id;
    }

    private function lineVariantId(InventoryOperationLine $line): int
    {
        return $line->product_variant_id;
    }

    private function operationId(InventoryOperation $operation): int
    {
        $key = $operation->getKey();

        if (! is_int($key)) {
            throw new \LogicException('Inventory operations must use integer identifiers.');
        }

        return $key;
    }

    private function actorId(?User $actor): ?int
    {
        if ($actor === null) {
            return null;
        }

        $actorId = $actor->getKey();

        if (! is_int($actorId)) {
            throw new \LogicException('Inventory operation actors must use integer identifiers.');
        }

        return $actorId;
    }

    private function lotId(?InventoryLot $lot): ?int
    {
        if ($lot === null) {
            return null;
        }

        return $lot->id;
    }

    private function decimal(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    private function nextOperationNumber(): string
    {
        $maxNumber = InventoryOperation::query()->whereNotNull('operation_number')->lockForUpdate()->max('operation_number');

        return sprintf('OP-%06d', is_string($maxNumber) ? (int) mb_substr($maxNumber, 3) + 1 : 1);
    }
}
