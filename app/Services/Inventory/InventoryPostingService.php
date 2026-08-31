<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryBalanceSnapshot;
use App\Data\Inventory\InventoryPostingCommand;
use App\Data\Inventory\InventoryPostingResult;
use App\Enums\MovementType;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\SerializedInventoryUnit;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * The canonical boundary for inventory balance and ledger mutations.
 *
 * Commands are locked by `(product_variant_id, warehouse_id)`, then by serial
 * identifiers before any materialized balance is changed. The initial Phase 2
 * consumer needs those two grains; later allocation phases will add lot and
 * reservation rows in that documented sequence.
 */
final readonly class InventoryPostingService
{
    private const QUANTITY_SCALE = 6;

    public function __construct(
        private InventoryBalanceService $inventoryBalanceService,
        private InventoryAlertService $inventoryAlertService,
    ) {}

    public function post(InventoryPostingCommand $command): InventoryPostingResult
    {
        return $this->postMany([$command])[0];
    }

    /**
     * Posts a command batch after locking affected balances in deterministic
     * variant/warehouse order. This avoids opposite-order transfer deadlocks
     * as consumers move to the canonical boundary.
     *
     * @param  list<InventoryPostingCommand>  $commands
     * @return list<InventoryPostingResult>
     */
    public function postMany(array $commands): array
    {
        if ($commands === []) {
            throw new DomainException('At least one inventory posting command is required.');
        }

        $this->validateCommands($commands);

        return DB::transaction(
            fn (): array => $this->postOrderedCommands($this->orderedCommands($commands)),
            attempts: 5,
        );
    }

    /** @param list<InventoryPostingCommand> $commands */
    private function validateCommands(array $commands): void
    {
        foreach ($commands as $command) {
            $this->validateCommand($command);
        }
    }

    /**
     * @param  list<InventoryPostingCommand>  $orderedCommands
     * @return list<InventoryPostingResult>
     */
    private function postOrderedCommands(array $orderedCommands): array
    {
        [$postingResults, $newCommands] = $this->existingAndNewCommands($orderedCommands);
        $stocks = $this->stocksForUpdate($newCommands);
        $lots = $this->lotsForUpdate($newCommands);
        $serializedUnits = $this->serializedUnitsForUpdate($newCommands);

        foreach ($newCommands as $command) {
            $postingResults[spl_object_id($command)] = $this->postNewCommand($command, $stocks, $lots, $serializedUnits);
        }

        return array_map(
            fn (InventoryPostingCommand $command): InventoryPostingResult => $postingResults[spl_object_id($command)],
            $orderedCommands,
        );
    }

    /**
     * @param  list<InventoryPostingCommand>  $commands
     * @return array{0: array<int, InventoryPostingResult>, 1: list<InventoryPostingCommand>}
     */
    private function existingAndNewCommands(array $commands): array
    {
        $postingResults = [];
        $newCommands = [];

        foreach ($commands as $command) {
            $existingPosting = $this->idempotentPostingResult($command);

            if ($existingPosting instanceof InventoryPostingResult) {
                $postingResults[spl_object_id($command)] = $existingPosting;
            } else {
                $newCommands[] = $command;
            }
        }

        return [$postingResults, $newCommands];
    }

    /**
     * @param  list<InventoryPostingCommand>  $commands
     * @return array<string, InventoryStock>
     */
    private function stocksForUpdate(array $commands): array
    {
        $stocks = [];

        foreach ($commands as $command) {
            $stockKey = $this->stockKey($command);

            if (! array_key_exists($stockKey, $stocks)) {
                $stocks[$stockKey] = $this->inventoryBalanceService->stockForUpdate(
                    $command->productVariantId,
                    $command->warehouseId,
                    $command->balanceMode->createsMissingBalance(),
                );
            }
        }

        return $stocks;
    }

    /**
     * @param  array<string, InventoryStock>  $stocks
     * @param  array<int, SerializedInventoryUnit>  $serializedUnits
     */
    private function postNewCommand(
        InventoryPostingCommand $command,
        array $stocks,
        array $lots,
        array $serializedUnits,
    ): InventoryPostingResult {
        $existingPosting = $this->idempotentPostingResult($command);

        if ($existingPosting instanceof InventoryPostingResult) {
            return $existingPosting;
        }

        return $this->createPostingResult($command, $stocks[$this->stockKey($command)], $lots, $serializedUnits);
    }

    /**
     * @param  array<int, SerializedInventoryUnit>  $serializedUnits
     */
    private function createPostingResult(
        InventoryPostingCommand $command,
        InventoryStock $stock,
        array $lots,
        array $serializedUnits,
    ): InventoryPostingResult {
        $balanceBefore = InventoryBalanceSnapshot::fromStock($stock);
        $this->assertMovementBalanceAvailability($stock, $command);
        $updatedStock = $this->inventoryBalanceService->applyLockedDeltas(
            $stock,
            $command->onHandBaseQuantityDelta,
            $command->reservedBaseQuantityDelta,
            $command->damagedBaseQuantityDelta,
        );

        $this->applyLotDeltas($command, $lots);
        $this->applySerializedTransition($command, $serializedUnits);

        return new InventoryPostingResult(
            stock: $updatedStock,
            movement: $this->recordMovement($command),
            balanceBefore: $balanceBefore,
            serializedUnit: $command->serializedInventoryUnitId === null
                ? null
                : $serializedUnits[$command->serializedInventoryUnitId],
            alreadyPosted: false,
        );
    }

    private function recordMovement(InventoryPostingCommand $command): InventoryMovement
    {
        return InventoryMovement::query()->forceCreate([
            'product_variant_id' => $command->productVariantId,
            'warehouse_id' => $command->warehouseId,
            'movement_type' => $command->movementType,
            'quantity' => $command->movementBaseQuantityDelta,
            'source_type' => $command->sourceType,
            'source_id' => $command->sourceId,
            'source_line_type' => $command->sourceLineType,
            'source_line_id' => $command->sourceLineId,
            'idempotency_key' => $command->idempotencyKey,
            'inventory_lot_id' => $command->inventoryLotId,
            'serialized_inventory_unit_id' => $command->serializedInventoryUnitId,
            'package_id' => $command->packageId,
            'transaction_quantity' => $command->transactionQuantity,
            'transaction_unit_id' => $command->transactionUnitId,
            'conversion_factor_snapshot' => $command->conversionFactorSnapshot,
            'base_quantity_delta' => $command->baseQuantityDelta,
            'status' => 'confirmed',
            'created_by' => $command->actorId,
            'notes' => $command->notes,
        ]);
    }

    /**
     * @param  list<InventoryPostingCommand>  $commands
     * @return list<InventoryPostingCommand>
     */
    private function orderedCommands(array $commands): array
    {
        $this->assertUniqueIdempotencyKeys($commands);

        usort($commands, fn (InventoryPostingCommand $left, InventoryPostingCommand $right): int => [
            $left->productVariantId,
            $left->warehouseId,
            $left->serializedInventoryUnitId ?? 0,
            $left->idempotencyKey ?? '',
        ] <=> [
            $right->productVariantId,
            $right->warehouseId,
            $right->serializedInventoryUnitId ?? 0,
            $right->idempotencyKey ?? '',
        ]);

        return $commands;
    }

    /** @param list<InventoryPostingCommand> $commands */
    private function assertUniqueIdempotencyKeys(array $commands): void
    {
        $idempotencyKeys = [];

        foreach ($commands as $command) {
            if ($command->idempotencyKey === null) {
                continue;
            }

            if (array_key_exists($command->idempotencyKey, $idempotencyKeys)) {
                throw new DomainException('A posting batch cannot contain the same idempotency key twice.');
            }

            $idempotencyKeys[$command->idempotencyKey] = true;
        }
    }

    /**
     * @param  list<InventoryPostingCommand>  $commands
     * @return array<int, InventoryLot>
     */
    private function lotsForUpdate(array $commands): array
    {
        $lotIds = [];

        foreach ($commands as $command) {
            if ($command->inventoryLotId !== null) {
                $lotIds[$command->inventoryLotId] = true;
            }
        }

        $lots = [];

        foreach (array_keys($lotIds) as $lotId) {
            $lot = InventoryLot::query()->lockForUpdate()->find($lotId);

            if (! $lot instanceof InventoryLot) {
                throw (new ModelNotFoundException)->setModel(InventoryLot::class, [$lotId]);
            }

            $lots[$lotId] = $lot;
        }

        return $lots;
    }

    /**
     * @param  list<InventoryPostingCommand>  $commands
     * @return array<int, SerializedInventoryUnit>
     */
    private function serializedUnitsForUpdate(array $commands): array
    {
        $serializedUnitIds = [];

        foreach ($commands as $command) {
            if ($command->serializedInventoryUnitId !== null) {
                $serializedUnitIds[$command->serializedInventoryUnitId] = true;
            }
        }

        $units = [];

        foreach (array_keys($serializedUnitIds) as $serializedUnitId) {
            $unit = SerializedInventoryUnit::query()->lockForUpdate()->find($serializedUnitId);

            if (! $unit instanceof SerializedInventoryUnit) {
                throw (new ModelNotFoundException)->setModel(SerializedInventoryUnit::class, [$serializedUnitId]);
            }

            $units[$serializedUnitId] = $unit;
        }

        return $units;
    }

    /**
     * Applies optional lot balance deltas in the same posting transaction.
     */
    private function applyLotDeltas(InventoryPostingCommand $command, array $lots): void
    {
        if ($command->inventoryLotId === null) {
            return;
        }

        $onHandDelta = $command->lotOnHandBaseQuantityDelta ?? '0';
        $reservedDelta = $command->lotReservedBaseQuantityDelta ?? '0';

        if (bccomp($this->baseDecimal($onHandDelta), '0', self::QUANTITY_SCALE) === 0
            && bccomp($this->baseDecimal($reservedDelta), '0', self::QUANTITY_SCALE) === 0) {
            return;
        }

        $lot = $lots[$command->inventoryLotId];
        $newOnHand = bcadd((string) $lot->on_hand_quantity, $onHandDelta, self::QUANTITY_SCALE);
        $newReserved = bcadd((string) $lot->reserved_quantity, $reservedDelta, self::QUANTITY_SCALE);

        if (
            bccomp($newOnHand, '0', self::QUANTITY_SCALE) < 0
            || bccomp($newReserved, '0', self::QUANTITY_SCALE) < 0
            || bccomp($newReserved, $newOnHand, self::QUANTITY_SCALE) > 0
        ) {
            throw new DomainException('Inventory lot balances cannot become negative or reserve more than on-hand.');
        }

        if (
            (int) $lot->product_variant_id !== $command->productVariantId
            || (int) $lot->warehouse_id !== $command->warehouseId
        ) {
            throw new DomainException('The inventory lot does not belong to the posting variant and warehouse.');
        }

        $lot->forceFill([
            'on_hand_quantity' => $newOnHand,
            'reserved_quantity' => $newReserved,
        ])->save();

        $this->inventoryAlertService->syncExpiry($lot->refresh());
    }

    /**
     * Applies optional serialized-unit custody/state changes under the row lock held by this posting.
     */
    private function applySerializedTransition(InventoryPostingCommand $command, array $serializedUnits): void
    {
        if ($command->serializedInventoryUnitId === null) {
            return;
        }

        if (
            $command->serializedTargetStatus === null
            && ! $command->serializedWarehouseSpecified
            && $command->serializedTargetCustodyType === null
            && $command->serializedTargetCustodyReferenceType === null
            && $command->serializedTargetCustodyReferenceId === null
        ) {
            return;
        }

        $unit = $serializedUnits[$command->serializedInventoryUnitId];

        if ((int) $unit->product_variant_id !== $command->productVariantId) {
            throw new DomainException('The serialized inventory unit does not belong to the posting variant.');
        }

        $attributes = [];

        if ($command->serializedTargetStatus !== null) {
            $attributes['status'] = $command->serializedTargetStatus;
        }

        if ($command->serializedWarehouseSpecified) {
            $attributes['warehouse_id'] = $command->serializedTargetWarehouseId;
        }

        if ($command->serializedTargetCustodyType !== null) {
            $attributes['custody_type'] = $command->serializedTargetCustodyType;
        }

        if ($command->serializedTargetCustodyReferenceType !== null) {
            $attributes['custody_reference_type'] = $command->serializedTargetCustodyReferenceType;
        }

        if ($command->serializedTargetCustodyReferenceId !== null) {
            $attributes['custody_reference_id'] = $command->serializedTargetCustodyReferenceId;
        }

        if ($attributes !== []) {
            $unit->forceFill($attributes)->save();
        }
    }

    private function existingPostingResult(InventoryMovement $existingMovement): InventoryPostingResult
    {
        $stock = InventoryStock::query()
            ->where('product_variant_id', $existingMovement->product_variant_id)
            ->where('warehouse_id', $existingMovement->warehouse_id)
            ->lockForUpdate()
            ->firstOrFail();

        return new InventoryPostingResult(
            stock: $stock,
            movement: $existingMovement,
            balanceBefore: InventoryBalanceSnapshot::fromStock($stock),
            serializedUnit: null,
            alreadyPosted: true,
        );
    }

    private function idempotentPostingResult(InventoryPostingCommand $command): ?InventoryPostingResult
    {
        if ($command->idempotencyKey === null) {
            return null;
        }

        $movement = InventoryMovement::query()
            ->where('idempotency_key', $command->idempotencyKey)
            ->lockForUpdate()
            ->first();

        if (! $movement instanceof InventoryMovement) {
            return null;
        }

        $this->assertIdempotentMatch($movement, $command);

        return $this->existingPostingResult($movement);
    }

    private function assertIdempotentMatch(InventoryMovement $movement, InventoryPostingCommand $command): void
    {
        $movementQuantity = $this->baseDecimal((string) $movement->quantity);
        $commandQuantity = $this->baseDecimal($command->movementBaseQuantityDelta);

        if (
            ! $this->matchesRequiredId($movement->product_variant_id, $command->productVariantId)
            || ! $this->matchesRequiredId($movement->warehouse_id, $command->warehouseId)
            || $movement->movement_type !== $command->movementType
            || bccomp($movementQuantity, $commandQuantity, self::QUANTITY_SCALE) !== 0
            || $movement->source_type !== $command->sourceType
            || ! $this->matchesRequiredId($movement->source_id, $command->sourceId)
            || ! $this->matchesNullableId($movement->serialized_inventory_unit_id, $command->serializedInventoryUnitId)
            || ! $this->matchesNullableId($movement->created_by, $command->actorId)
            || $movement->notes !== $command->notes
        ) {
            throw new DomainException('The idempotency key is already used by a different inventory posting.');
        }
    }

    private function validateCommand(InventoryPostingCommand $command): void
    {
        $this->assertCommandIdentifiers($command);
        $this->assertSourceReference($command);
        $this->assertIdempotencyKey($command);
        $this->assertSourceLineReference($command);
        $this->assertQuantitySnapshot($command);
        $this->assertLotMutation($command);
        $this->assertSerializedTransition($command);
        $this->assertMaterializedBalanceChange($command);
    }

    private function assertCommandIdentifiers(InventoryPostingCommand $command): void
    {
        if ($command->productVariantId <= 0 || $command->warehouseId <= 0 || $command->sourceId <= 0) {
            throw new DomainException('Inventory posting identifiers must be positive integers.');
        }

        foreach ([
            $command->actorId,
            $command->serializedInventoryUnitId,
            $command->inventoryLotId,
            $command->packageId,
            $command->serializedTargetWarehouseId,
            $command->serializedTargetCustodyReferenceId,
        ] as $identifier) {
            if ($identifier !== null && $identifier <= 0) {
                throw new DomainException('Inventory posting identifiers must be positive integers.');
            }
        }
    }

    private function assertSourceReference(InventoryPostingCommand $command): void
    {
        if (mb_trim($command->sourceType) === '') {
            throw new DomainException('Inventory postings require a source document reference.');
        }
    }

    private function assertIdempotencyKey(InventoryPostingCommand $command): void
    {
        if ($command->idempotencyKey !== null && (mb_trim($command->idempotencyKey) === '' || mb_strlen($command->idempotencyKey) > 191)) {
            throw new DomainException('Inventory posting idempotency keys must be non-empty and at most 191 characters.');
        }
    }

    private function assertLotMutation(InventoryPostingCommand $command): void
    {
        $onHand = $command->lotOnHandBaseQuantityDelta;
        $reserved = $command->lotReservedBaseQuantityDelta;

        if ($onHand === null && $reserved === null) {
            return;
        }

        $this->baseDecimal($onHand ?? '0');
        $this->baseDecimal($reserved ?? '0');

        if ($command->inventoryLotId === null) {
            throw new DomainException('Inventory lot deltas require an inventory lot identifier.');
        }
    }

    private function assertSerializedTransition(InventoryPostingCommand $command): void
    {
        $hasTransition = $command->serializedTargetStatus !== null
            || $command->serializedWarehouseSpecified
            || $command->serializedTargetCustodyType !== null
            || $command->serializedTargetCustodyReferenceType !== null
            || $command->serializedTargetCustodyReferenceId !== null;

        if ($hasTransition && $command->serializedInventoryUnitId === null) {
            throw new DomainException('Serialized inventory state changes require a serialized inventory unit identifier.');
        }

        if (($command->serializedTargetCustodyReferenceType === null) !== ($command->serializedTargetCustodyReferenceId === null)) {
            throw new DomainException('Serialized custody references must provide both type and identifier.');
        }
    }

    private function assertMaterializedBalanceChange(InventoryPostingCommand $command): void
    {

        $onHandDelta = $this->baseDecimal($command->onHandBaseQuantityDelta);
        $reservedDelta = $this->baseDecimal($command->reservedBaseQuantityDelta);
        $damagedDelta = $this->baseDecimal($command->damagedBaseQuantityDelta);
        $this->baseDecimal($command->movementBaseQuantityDelta);

        $lotOnHandDelta = $this->baseDecimal($command->lotOnHandBaseQuantityDelta ?? '0');
        $lotReservedDelta = $this->baseDecimal($command->lotReservedBaseQuantityDelta ?? '0');
        $hasSerializedTransition = $command->serializedTargetStatus !== null
            || $command->serializedWarehouseSpecified
            || $command->serializedTargetCustodyType !== null
            || $command->serializedTargetCustodyReferenceType !== null
            || $command->serializedTargetCustodyReferenceId !== null;

        if (
            bccomp($onHandDelta, '0', self::QUANTITY_SCALE) === 0
            && bccomp($reservedDelta, '0', self::QUANTITY_SCALE) === 0
            && bccomp($damagedDelta, '0', self::QUANTITY_SCALE) === 0
            && bccomp($lotOnHandDelta, '0', self::QUANTITY_SCALE) === 0
            && bccomp($lotReservedDelta, '0', self::QUANTITY_SCALE) === 0
            && ! $hasSerializedTransition
            && $command->movementType !== MovementType::Adjustment
        ) {
            throw new DomainException('An inventory posting must change stock, lot allocation, or serialized custody.');
        }
    }

    private function assertMovementBalanceAvailability(InventoryStock $stock, InventoryPostingCommand $command): void
    {
        $onHandQuantity = $this->baseDecimal((string) $stock->on_hand_quantity);
        $reservedQuantity = $this->baseDecimal((string) $stock->reserved_quantity);
        $damagedQuantity = $this->baseDecimal((string) $stock->damaged_quantity);
        $damagedDelta = $this->baseDecimal($command->damagedBaseQuantityDelta);

        if ($command->movementType === MovementType::Damage) {
            $this->assertDamageQuantityIsAvailable($onHandQuantity, $reservedQuantity, $damagedQuantity, $damagedDelta);

            return;
        }

        if (
            ($command->movementType === MovementType::DamageRecovery || $command->movementType === MovementType::Disposal)
        ) {
            $this->assertDamagedQuantityIsAvailable($damagedQuantity, $damagedDelta);
        }
    }

    /**
     * @param  numeric-string  $onHandQuantity
     * @param  numeric-string  $reservedQuantity
     * @param  numeric-string  $damagedQuantity
     * @param  numeric-string  $damagedDelta
     */
    private function assertDamageQuantityIsAvailable(
        string $onHandQuantity,
        string $reservedQuantity,
        string $damagedQuantity,
        string $damagedDelta,
    ): void {
        $availableQuantity = bcsub(bcsub($onHandQuantity, $reservedQuantity, self::QUANTITY_SCALE), $damagedQuantity, self::QUANTITY_SCALE);

        if (bccomp($availableQuantity, $damagedDelta, self::QUANTITY_SCALE) < 0) {
            throw new DomainException(__('admin.inventory.balance.errors.insufficient_available'));
        }
    }

    /**
     * @param  numeric-string  $damagedQuantity
     * @param  numeric-string  $damagedDelta
     */
    private function assertDamagedQuantityIsAvailable(string $damagedQuantity, string $damagedDelta): void
    {
        if (bccomp($damagedQuantity, bcsub('0', $damagedDelta, self::QUANTITY_SCALE), self::QUANTITY_SCALE) < 0) {
            throw new DomainException(__('admin.inventory.balance.errors.insufficient_damaged'));
        }
    }

    /** @return numeric-string */
    private function baseDecimal(string $quantity): string
    {
        if (! is_numeric($quantity) || preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d{1,6})?$/D', $quantity) !== 1) {
            throw new DomainException('Inventory posting quantities must be exact base-UOM decimal strings with at most six places.');
        }

        return $quantity;
    }

    private function stockKey(InventoryPostingCommand $command): string
    {
        return $command->productVariantId.':'.$command->warehouseId;
    }

    private function matchesRequiredId(mixed $actual, int $expected): bool
    {
        return (is_int($actual) || (is_string($actual) && ctype_digit($actual)))
            && (int) $actual === $expected;
    }

    private function matchesNullableId(mixed $actual, ?int $expected): bool
    {
        if ($expected === null) {
            return $actual === null;
        }

        return $this->matchesRequiredId($actual, $expected);
    }

    private function assertSourceLineReference(InventoryPostingCommand $command): void
    {
        if ($command->sourceLineType === null && $command->sourceLineId === null) {
            return;
        }

        if (mb_trim((string) $command->sourceLineType) === '' || $command->sourceLineId === null || $command->sourceLineId <= 0) {
            throw new DomainException('Inventory postings require complete source-line references.');
        }
    }

    private function assertQuantitySnapshot(InventoryPostingCommand $command): void
    {
        if (
            $command->transactionQuantity === null
            && $command->transactionUnitId === null
            && $command->conversionFactorSnapshot === null
            && $command->baseQuantityDelta === null
        ) {
            return;
        }

        if (
            $command->transactionQuantity === null
            || $command->transactionUnitId === null
            || $command->conversionFactorSnapshot === null
            || $command->baseQuantityDelta === null
            || $command->transactionUnitId <= 0
        ) {
            throw new DomainException('Inventory postings require complete transaction-UOM snapshots.');
        }

        $transactionQuantity = $this->baseDecimal($command->transactionQuantity);
        $conversionFactor = $this->baseDecimal($command->conversionFactorSnapshot);
        $baseQuantityDelta = $this->baseDecimal($command->baseQuantityDelta);
        $movementQuantity = $this->baseDecimal($command->movementBaseQuantityDelta);

        if (
            bccomp($transactionQuantity, '0', self::QUANTITY_SCALE) <= 0
            || bccomp($conversionFactor, '0', self::QUANTITY_SCALE) <= 0
            || bccomp($baseQuantityDelta, $movementQuantity, self::QUANTITY_SCALE) !== 0
        ) {
            throw new DomainException('The inventory posting transaction-UOM snapshot is invalid.');
        }
    }
}
