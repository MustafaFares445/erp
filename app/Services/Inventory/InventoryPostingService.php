<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryBalanceSnapshot;
use App\Data\Inventory\InventoryPostingCommand;
use App\Data\Inventory\InventoryPostingResult;
use App\Enums\MovementType;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\SerializedInventoryUnit;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
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
    private const int QUANTITY_SCALE = 6;

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
        $conditionBalances = $this->conditionBalancesForUpdate($newCommands, $stocks);
        $lots = $this->lotsForUpdate($newCommands);
        $lotConditionBalances = $this->lotConditionBalancesForUpdate($newCommands, $lots);
        $serializedUnits = $this->serializedUnitsForUpdate($newCommands);

        foreach ($newCommands as $command) {
            $postingResults[spl_object_id($command)] = $this->postNewCommand(
                $command,
                $stocks,
                $conditionBalances,
                $lots,
                $lotConditionBalances,
                $serializedUnits,
            );
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
     * @param  array<string, InventoryConditionBalance>  $conditionBalances
     * @param  array<int, InventoryLot>  $lots
     * @param  array<string, InventoryLotBalance>  $lotConditionBalances
     * @param  array<int, SerializedInventoryUnit>  $serializedUnits
     */
    private function postNewCommand(
        InventoryPostingCommand $command,
        array $stocks,
        array $conditionBalances,
        array $lots,
        array $lotConditionBalances,
        array $serializedUnits,
    ): InventoryPostingResult {
        $existingPosting = $this->idempotentPostingResult($command);

        if ($existingPosting instanceof InventoryPostingResult) {
            return $existingPosting;
        }

        return $this->createPostingResult(
            $command,
            $stocks[$this->stockKey($command)],
            $conditionBalances,
            $lots,
            $lotConditionBalances,
            $serializedUnits,
        );
    }

    /**
     * @param  array<string, InventoryConditionBalance>  $conditionBalances
     * @param  array<int, InventoryLot>  $lots
     * @param  array<string, InventoryLotBalance>  $lotConditionBalances
     * @param  array<int, SerializedInventoryUnit>  $serializedUnits
     */
    private function createPostingResult(
        InventoryPostingCommand $command,
        InventoryStock $stock,
        array $conditionBalances,
        array $lots,
        array $lotConditionBalances,
        array $serializedUnits,
    ): InventoryPostingResult {
        $balanceBefore = InventoryBalanceSnapshot::fromStock($stock);
        $this->assertMovementBalanceAvailability($stock, $command);

        $conditionBefore = $this->conditionSnapshot($command, $conditionBalances);

        $updatedStock = $this->inventoryBalanceService->applyLockedDeltas(
            $stock,
            $command->onHandBaseQuantityDelta,
            $command->reservedBaseQuantityDelta,
            $command->damagedBaseQuantityDelta,
        );

        $this->applyConditionDeltas($command, $conditionBalances);
        $this->reconcileStockCompatibility($updatedStock, $conditionBalances);

        $this->applyLotConditionDeltas($command, $lotConditionBalances);

        if ($command->inventoryLotId !== null) {
            $this->inventoryAlertService->syncExpiry($lots[$command->inventoryLotId]);
        }

        $this->applySerializedTransition($command, $serializedUnits, $lots);

        $conditionAfter = $this->conditionSnapshot($command, $conditionBalances);

        return new InventoryPostingResult(
            stock: $updatedStock->refresh(),
            movement: $this->recordMovement($command, $conditionBefore, $conditionAfter),
            balanceBefore: $balanceBefore,
            serializedUnit: $command->serializedInventoryUnitId === null
                ? null
                : $serializedUnits[$command->serializedInventoryUnitId]->refresh(),
            alreadyPosted: false,
        );
    }

    /**
     * @param array{
     *   from: StockCondition,
     *   to: StockCondition,
     *   from_on_hand: numeric-string|null,
     *   from_reserved: numeric-string|null,
     *   to_on_hand: numeric-string|null,
     *   to_reserved: numeric-string|null
     * } $before
     * @param array{
     *   from: StockCondition,
     *   to: StockCondition,
     *   from_on_hand: numeric-string|null,
     *   from_reserved: numeric-string|null,
     *   to_on_hand: numeric-string|null,
     *   to_reserved: numeric-string|null
     * } $after
     */
    private function recordMovement(
        InventoryPostingCommand $command,
        array $before,
        array $after,
    ): InventoryMovement {
        return InventoryMovement::query()->forceCreate([
            'product_variant_id' => $command->productVariantId,
            'warehouse_id' => $command->warehouseId,
            'movement_type' => $command->movementType,
            'quantity' => $command->movementBaseQuantityDelta,
            'source_type' => $command->sourceType,
            'source_id' => $command->sourceId,
            'source_line_type' => $command->sourceLineType,
            'source_line_id' => $command->sourceLineId,
            'reversal_of_movement_id' => $command->reversalOfMovementId,
            'idempotency_key' => $command->idempotencyKey,
            'inventory_lot_id' => $command->inventoryLotId,
            'serialized_inventory_unit_id' => $command->serializedInventoryUnitId,
            'package_id' => $command->packageId,
            'transaction_quantity' => $command->transactionQuantity,
            'transaction_unit_id' => $command->transactionUnitId,
            'conversion_factor_snapshot' => $command->conversionFactorSnapshot,
            'base_quantity_delta' => $command->baseQuantityDelta,
            'stock_condition_from' => $before['from'],
            'stock_condition_to' => $before['to'],
            'condition_from_on_hand_before' => $before['from_on_hand'],
            'condition_from_on_hand_after' => $after['from_on_hand'],
            'condition_from_reserved_before' => $before['from_reserved'],
            'condition_from_reserved_after' => $after['from_reserved'],
            'condition_to_on_hand_before' => $before['to_on_hand'],
            'condition_to_on_hand_after' => $after['to_on_hand'],
            'condition_to_reserved_before' => $before['to_reserved'],
            'condition_to_reserved_after' => $after['to_reserved'],
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
     * @param  array<string, InventoryStock>  $stocks
     * @return array<string, InventoryConditionBalance>
     */
    private function conditionBalancesForUpdate(array $commands, array $stocks): array
    {
        $balances = [];
        $handledStocks = [];

        foreach ($commands as $command) {
            $stockKey = $this->stockKey($command);

            if (isset($handledStocks[$stockKey])) {
                continue;
            }

            $stock = $stocks[$stockKey];
            $hasAny = InventoryConditionBalance::query()
                ->where('product_variant_id', $command->productVariantId)
                ->where('warehouse_id', $command->warehouseId)
                ->exists();

            foreach ($this->materializedConditions() as $condition) {
                $balance = $this->conditionBalanceForUpdate(
                    $stock,
                    $condition,
                    initializeFromCompatibility: ! $hasAny,
                );

                $balances[$this->conditionKey(
                    $command->productVariantId,
                    $command->warehouseId,
                    $condition,
                )] = $balance;
            }

            $handledStocks[$stockKey] = true;
        }

        return $balances;
    }

    private function conditionBalanceForUpdate(
        InventoryStock $stock,
        StockCondition $condition,
        bool $initializeFromCompatibility,
    ): InventoryConditionBalance {
        $query = InventoryConditionBalance::query()
            ->where('product_variant_id', $stock->product_variant_id)
            ->where('warehouse_id', $stock->warehouse_id)
            ->where('stock_condition', $condition->value);

        $balance = $query->lockForUpdate()->first();

        if ($balance instanceof InventoryConditionBalance) {
            return $balance;
        }

        [$onHand, $reserved] = $initializeFromCompatibility
            ? $this->initialConditionBalance($stock, $condition)
            : ['0.000000', '0.000000'];

        try {
            InventoryConditionBalance::query()->forceCreate([
                'product_variant_id' => $stock->product_variant_id,
                'warehouse_id' => $stock->warehouse_id,
                'stock_condition' => $condition,
                'on_hand_base_quantity' => $onHand,
                'reserved_base_quantity' => $reserved,
            ]);
        } catch (QueryException $queryException) {
            $concurrent = $query->lockForUpdate()->first();

            if ($concurrent instanceof InventoryConditionBalance) {
                return $concurrent;
            }

            throw $queryException;
        }

        return $query->lockForUpdate()->firstOrFail();
    }

    /** @return array{numeric-string, numeric-string} */
    private function initialConditionBalance(InventoryStock $stock, StockCondition $condition): array
    {
        $onHand = $this->baseDecimal((string) $stock->on_hand_quantity);
        $reserved = $this->baseDecimal((string) $stock->reserved_quantity);
        $damaged = $this->baseDecimal((string) $stock->damaged_quantity);

        [$conditionOnHand, $conditionReserved] = match ($condition) {
            StockCondition::Saleable => [bcsub($onHand, $damaged, self::QUANTITY_SCALE), $reserved],
            StockCondition::Quarantine => ['0.000000', '0.000000'],
            StockCondition::Damaged => [$damaged, '0.000000'],
            StockCondition::Disposed => throw new DomainException('Disposed stock is not a materialized warehouse balance.'),
        };

        $this->assertConditionQuantities($condition, $conditionOnHand, $conditionReserved);

        return [$conditionOnHand, $conditionReserved];
    }

    /**
     * @param  list<InventoryPostingCommand>  $commands
     * @param  array<int, InventoryLot>  $lots
     * @return array<string, InventoryLotBalance>
     */
    private function lotConditionBalancesForUpdate(array $commands, array $lots): array
    {
        $balances = [];
        $handled = [];

        foreach ($commands as $command) {
            if ($command->inventoryLotId === null) {
                continue;
            }

            $grainKey = $command->inventoryLotId.':'.$command->warehouseId;

            if (isset($handled[$grainKey])) {
                continue;
            }

            $lot = $lots[$command->inventoryLotId];

            if ((int) $lot->product_variant_id !== $command->productVariantId) {
                throw new DomainException('The inventory lot does not belong to the posting variant.');
            }

            foreach ($this->materializedConditions() as $condition) {
                $balance = $this->lotConditionBalanceForUpdate(
                    $lot,
                    $command->warehouseId,
                    $condition,
                );

                $balances[$this->lotConditionKey(
                    $command->inventoryLotId,
                    $command->warehouseId,
                    $condition,
                )] = $balance;
            }

            $handled[$grainKey] = true;
        }

        return $balances;
    }

    private function lotConditionBalanceForUpdate(
        InventoryLot $lot,
        int $warehouseId,
        StockCondition $condition,
    ): InventoryLotBalance {
        $query = InventoryLotBalance::query()
            ->where('inventory_lot_id', $lot->getKey())
            ->where('warehouse_id', $warehouseId)
            ->where('stock_condition', $condition->value);

        $balance = $query->lockForUpdate()->first();

        if ($balance instanceof InventoryLotBalance) {
            return $balance;
        }

        try {
            InventoryLotBalance::query()->forceCreate([
                'inventory_lot_id' => $lot->getKey(),
                'warehouse_id' => $warehouseId,
                'stock_condition' => $condition,
                'on_hand_base_quantity' => '0.000000',
                'reserved_base_quantity' => '0.000000',
            ]);
        } catch (QueryException $queryException) {
            $concurrent = $query->lockForUpdate()->first();

            if ($concurrent instanceof InventoryLotBalance) {
                return $concurrent;
            }

            throw $queryException;
        }

        return $query->lockForUpdate()->firstOrFail();
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

            if ($command->serializedInventoryLotSpecified && $command->serializedTargetInventoryLotId !== null) {
                $lotIds[$command->serializedTargetInventoryLotId] = true;
            }
        }

        $lots = [];

        foreach (array_keys($lotIds) as $lotId) {
            $lot = InventoryLot::query()->lockForUpdate()->find($lotId);

            if (! $lot instanceof InventoryLot) {
                throw (new ModelNotFoundException)->setModel(InventoryLot::class, [$lotId]);
            }

            if ($lot->canonical_inventory_lot_id !== null) {
                throw new DomainException('Inventory posting requires a canonical lot identity.');
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

    /** @param array<string, InventoryConditionBalance> $balances */
    private function applyConditionDeltas(InventoryPostingCommand $command, array $balances): void
    {
        $transitionQuantity = $command->conditionTransferBaseQuantity;

        if ($transitionQuantity !== null && bccomp($this->baseDecimal($transitionQuantity), '0', self::QUANTITY_SCALE) > 0) {
            if (! $command->conditionFrom instanceof StockCondition) {
                throw new DomainException('Condition transfers require a materialized condition to transfer from.');
            }

            $quantity = $this->baseDecimal($transitionQuantity);
            $this->mutateConditionBalance(
                $balances[$this->conditionKey(
                    $command->productVariantId,
                    $command->warehouseId,
                    $command->conditionFrom,
                )],
                bcsub('0', $quantity, self::QUANTITY_SCALE),
                '0',
            );

            if ($command->conditionTo?->isMaterialized() === true) {
                $this->mutateConditionBalance(
                    $balances[$this->conditionKey(
                        $command->productVariantId,
                        $command->warehouseId,
                        $command->conditionTo,
                    )],
                    $quantity,
                    '0',
                );
            }

            return;
        }

        $this->mutateConditionBalance(
            $balances[$this->conditionKey(
                $command->productVariantId,
                $command->warehouseId,
                $command->stockCondition,
            )],
            $command->onHandBaseQuantityDelta,
            $command->reservedBaseQuantityDelta,
        );
    }

    private function mutateConditionBalance(
        InventoryConditionBalance $balance,
        string $onHandDelta,
        string $reservedDelta,
    ): void {
        $newOnHand = bcadd(
            (string) $balance->on_hand_base_quantity,
            $this->baseDecimal($onHandDelta),
            self::QUANTITY_SCALE,
        );
        $newReserved = bcadd(
            (string) $balance->reserved_base_quantity,
            $this->baseDecimal($reservedDelta),
            self::QUANTITY_SCALE,
        );

        $this->assertConditionQuantities(
            $balance->stock_condition,
            $newOnHand,
            $newReserved,
        );

        $balance->forceFill([
            'on_hand_base_quantity' => $newOnHand,
            'reserved_base_quantity' => $newReserved,
        ])->save();
    }

    /** @param array<string, InventoryLotBalance> $balances */
    private function applyLotConditionDeltas(InventoryPostingCommand $command, array $balances): void
    {
        if ($command->inventoryLotId === null) {
            return;
        }

        $transitionQuantity = $command->conditionTransferBaseQuantity;

        if ($transitionQuantity !== null && bccomp($this->baseDecimal($transitionQuantity), '0', self::QUANTITY_SCALE) > 0) {
            if (! $command->conditionFrom instanceof StockCondition) {
                throw new DomainException('Condition transfers require a materialized condition to transfer from.');
            }

            $quantity = $this->baseDecimal($transitionQuantity);
            $this->mutateLotConditionBalance(
                $balances[$this->lotConditionKey(
                    $command->inventoryLotId,
                    $command->warehouseId,
                    $command->conditionFrom,
                )],
                bcsub('0', $quantity, self::QUANTITY_SCALE),
                '0',
            );

            if ($command->conditionTo?->isMaterialized() === true) {
                $this->mutateLotConditionBalance(
                    $balances[$this->lotConditionKey(
                        $command->inventoryLotId,
                        $command->warehouseId,
                        $command->conditionTo,
                    )],
                    $quantity,
                    '0',
                );
            }

            return;
        }

        $onHandDelta = $command->lotOnHandBaseQuantityDelta ?? '0';
        $reservedDelta = $command->lotReservedBaseQuantityDelta ?? '0';

        if (
            bccomp($this->baseDecimal($onHandDelta), '0', self::QUANTITY_SCALE) === 0
            && bccomp($this->baseDecimal($reservedDelta), '0', self::QUANTITY_SCALE) === 0
        ) {
            return;
        }

        $this->mutateLotConditionBalance(
            $balances[$this->lotConditionKey(
                $command->inventoryLotId,
                $command->warehouseId,
                $command->stockCondition,
            )],
            $onHandDelta,
            $reservedDelta,
        );
    }

    private function mutateLotConditionBalance(
        InventoryLotBalance $balance,
        string $onHandDelta,
        string $reservedDelta,
    ): void {
        $newOnHand = bcadd(
            (string) $balance->on_hand_base_quantity,
            $this->baseDecimal($onHandDelta),
            self::QUANTITY_SCALE,
        );
        $newReserved = bcadd(
            (string) $balance->reserved_base_quantity,
            $this->baseDecimal($reservedDelta),
            self::QUANTITY_SCALE,
        );

        $this->assertConditionQuantities(
            $balance->stock_condition,
            $newOnHand,
            $newReserved,
        );

        $balance->forceFill([
            'on_hand_base_quantity' => $newOnHand,
            'reserved_base_quantity' => $newReserved,
        ])->save();
    }

    /**
     * @param  numeric-string  $onHand
     * @param  numeric-string  $reserved
     */
    private function assertConditionQuantities(
        StockCondition $condition,
        string $onHand,
        string $reserved,
    ): void {
        if (
            bccomp($onHand, '0', self::QUANTITY_SCALE) < 0
            || bccomp($reserved, '0', self::QUANTITY_SCALE) < 0
        ) {
            throw new DomainException('Inventory condition balances cannot become negative.');
        }

        if (! $condition->allowsReservation() && bccomp($reserved, '0', self::QUANTITY_SCALE) !== 0) {
            throw new DomainException('Only saleable stock may carry a reservation.');
        }

        if ($condition->allowsReservation() && bccomp($reserved, $onHand, self::QUANTITY_SCALE) > 0) {
            throw new DomainException('Saleable reserved quantity cannot exceed saleable on-hand.');
        }
    }

    /** @param array<string, InventoryConditionBalance> $balances */
    private function reconcileStockCompatibility(InventoryStock $stock, array $balances): void
    {
        $saleable = $balances[$this->conditionKey(
            (int) $stock->product_variant_id,
            (int) $stock->warehouse_id,
            StockCondition::Saleable,
        )];
        $quarantine = $balances[$this->conditionKey(
            (int) $stock->product_variant_id,
            (int) $stock->warehouse_id,
            StockCondition::Quarantine,
        )];
        $damaged = $balances[$this->conditionKey(
            (int) $stock->product_variant_id,
            (int) $stock->warehouse_id,
            StockCondition::Damaged,
        )];

        $derivedOnHand = bcadd(
            bcadd(
                (string) $saleable->on_hand_base_quantity,
                (string) $quarantine->on_hand_base_quantity,
                self::QUANTITY_SCALE,
            ),
            (string) $damaged->on_hand_base_quantity,
            self::QUANTITY_SCALE,
        );
        $derivedReserved = $this->baseDecimal((string) $saleable->reserved_base_quantity);
        $derivedDamaged = $this->baseDecimal((string) $damaged->on_hand_base_quantity);
        $derivedAvailable = bcsub(
            (string) $saleable->on_hand_base_quantity,
            $derivedReserved,
            self::QUANTITY_SCALE,
        );

        if (
            bccomp((string) $stock->on_hand_quantity, $derivedOnHand, self::QUANTITY_SCALE) !== 0
            || bccomp((string) $stock->reserved_quantity, $derivedReserved, self::QUANTITY_SCALE) !== 0
            || bccomp((string) $stock->damaged_quantity, $derivedDamaged, self::QUANTITY_SCALE) !== 0
        ) {
            throw new DomainException('Canonical stock condition balances do not reconcile with the compatibility stock row.');
        }

        $stock->forceFill(['available_quantity' => $derivedAvailable])->save();
    }

    /**
     * @param  array<string, InventoryConditionBalance>  $balances
     * @return array{
     *   from: StockCondition,
     *   to: StockCondition,
     *   from_on_hand: numeric-string|null,
     *   from_reserved: numeric-string|null,
     *   to_on_hand: numeric-string|null,
     *   to_reserved: numeric-string|null
     * }
     */
    private function conditionSnapshot(InventoryPostingCommand $command, array $balances): array
    {
        $from = $command->conditionFrom ?? $command->stockCondition;
        $to = $command->conditionTo ?? $command->stockCondition;

        $fromBalance = $from->isMaterialized()
            ? $balances[$this->conditionKey(
                $command->productVariantId,
                $command->warehouseId,
                $from,
            )]
            : null;
        $toBalance = $to->isMaterialized()
            ? $balances[$this->conditionKey(
                $command->productVariantId,
                $command->warehouseId,
                $to,
            )]
            : null;

        return [
            'from' => $from,
            'to' => $to,
            'from_on_hand' => $fromBalance?->on_hand_base_quantity,
            'from_reserved' => $fromBalance?->reserved_base_quantity,
            'to_on_hand' => $toBalance?->on_hand_base_quantity,
            'to_reserved' => $toBalance?->reserved_base_quantity,
        ];
    }

    /**
     * Applies optional serialized-unit custody/state changes under the row lock held by this posting.
     *
     * @param  array<int, SerializedInventoryUnit>  $serializedUnits
     * @param  array<int, InventoryLot>  $lots
     */
    private function applySerializedTransition(
        InventoryPostingCommand $command,
        array $serializedUnits,
        array $lots,
    ): void {
        if ($command->serializedInventoryUnitId === null) {
            return;
        }

        if (
            ! $command->serializedTargetStatus instanceof SerializedInventoryUnitStatus
            && ! $command->serializedWarehouseSpecified
            && ! $command->serializedTargetCustodyType instanceof SerializedCustodyType
            && $command->serializedTargetCustodyReferenceType === null
            && $command->serializedTargetCustodyReferenceId === null
            && ! $command->serializedTargetStockCondition instanceof StockCondition
            && ! $command->serializedInventoryLotSpecified
        ) {
            return;
        }

        $unit = $serializedUnits[$command->serializedInventoryUnitId];

        if ((int) $unit->product_variant_id !== $command->productVariantId) {
            throw new DomainException('The serialized inventory unit does not belong to the posting variant.');
        }

        $attributes = [];

        if ($command->serializedTargetStatus instanceof SerializedInventoryUnitStatus) {
            $attributes['status'] = $command->serializedTargetStatus;
        }

        if ($command->serializedWarehouseSpecified) {
            $attributes['warehouse_id'] = $command->serializedTargetWarehouseId;
        }

        if ($command->serializedTargetCustodyType instanceof SerializedCustodyType) {
            $attributes['custody_type'] = $command->serializedTargetCustodyType;
        }

        if ($command->serializedTargetCustodyReferenceType !== null) {
            $attributes['custody_reference_type'] = $command->serializedTargetCustodyReferenceType;
        }

        if ($command->serializedTargetCustodyReferenceId !== null) {
            $attributes['custody_reference_id'] = $command->serializedTargetCustodyReferenceId;
        }

        if ($command->serializedTargetStockCondition instanceof StockCondition) {
            $attributes['stock_condition'] = $command->serializedTargetStockCondition;
        }

        if ($command->serializedInventoryLotSpecified) {
            if ($command->serializedTargetInventoryLotId !== null) {
                $targetLot = $lots[$command->serializedTargetInventoryLotId] ?? null;

                if (
                    ! $targetLot instanceof InventoryLot
                    || (int) $targetLot->product_variant_id !== $command->productVariantId
                ) {
                    throw new DomainException('The serialized target lot does not belong to the posting variant.');
                }
            }

            $attributes['inventory_lot_id'] = $command->serializedTargetInventoryLotId;
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
            || ! $this->matchesNullableId($movement->reversal_of_movement_id, $command->reversalOfMovementId)
            || (
                $movement->stock_condition_from !== null
                && $movement->stock_condition_from !== ($command->conditionFrom ?? $command->stockCondition)
            )
            || (
                $movement->stock_condition_to !== null
                && $movement->stock_condition_to !== ($command->conditionTo ?? $command->stockCondition)
            )
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
        $this->assertReversalReference($command);
        $this->assertQuantitySnapshot($command);
        $this->assertLotMutation($command);
        $this->assertConditionMutation($command);
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
            $command->serializedTargetInventoryLotId,
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

    private function assertConditionMutation(InventoryPostingCommand $command): void
    {
        if (! $command->stockCondition->isMaterialized()) {
            throw new DomainException('Disposed stock cannot be used as a materialized posting condition.');
        }

        if (! $command->stockCondition->allowsReservation()
            && bccomp($this->baseDecimal($command->reservedBaseQuantityDelta), '0', self::QUANTITY_SCALE) !== 0) {
            throw new DomainException('Only saleable stock may carry reservation deltas.');
        }

        $hasTransition = $command->conditionFrom instanceof StockCondition
            || $command->conditionTo instanceof StockCondition
            || $command->conditionTransferBaseQuantity !== null;

        if (! $hasTransition) {
            if ($command->stockCondition === StockCondition::Damaged
                && bccomp(
                    $this->baseDecimal($command->damagedBaseQuantityDelta),
                    $this->baseDecimal($command->onHandBaseQuantityDelta),
                    self::QUANTITY_SCALE,
                ) !== 0) {
                throw new DomainException('Damaged-condition physical postings must mirror the damaged compatibility delta.');
            }

            if ($command->stockCondition !== StockCondition::Damaged
                && bccomp($this->baseDecimal($command->damagedBaseQuantityDelta), '0', self::QUANTITY_SCALE) !== 0) {
                throw new DomainException('Non-damaged physical postings cannot mutate damaged compatibility quantity.');
            }

            return;
        }

        if (
            ! $command->conditionFrom instanceof StockCondition
            || ! $command->conditionTo instanceof StockCondition
            || $command->conditionTransferBaseQuantity === null
        ) {
            throw new DomainException('Condition transfers require from, to, and base quantity.');
        }

        if (! $command->conditionFrom->isMaterialized()) {
            throw new DomainException('Condition transfers must originate from a materialized condition.');
        }

        $quantity = $this->baseDecimal($command->conditionTransferBaseQuantity);
        $movementQuantity = $this->baseDecimal($command->movementBaseQuantityDelta);
        $absoluteMovementQuantity = bccomp($movementQuantity, '0', self::QUANTITY_SCALE) < 0
            ? bcsub('0', $movementQuantity, self::QUANTITY_SCALE)
            : $movementQuantity;

        if (bccomp($quantity, '0', self::QUANTITY_SCALE) <= 0) {
            throw new DomainException('Condition transfer quantity must be positive.');
        }

        if (bccomp($this->baseDecimal($command->reservedBaseQuantityDelta), '0', self::QUANTITY_SCALE) !== 0) {
            throw new DomainException('Condition transfers cannot carry reservation deltas.');
        }

        if (bccomp($absoluteMovementQuantity, $quantity, self::QUANTITY_SCALE) !== 0) {
            throw new DomainException('Condition transfers must record their full base quantity in the movement ledger.');
        }

        $expectedOnHandDelta = $command->conditionTo->isMaterialized()
            ? '0.000000'
            : bcsub('0', $quantity, self::QUANTITY_SCALE);
        $expectedDamagedDelta = bcsub(
            $command->conditionTo === StockCondition::Damaged ? $quantity : '0.000000',
            $command->conditionFrom === StockCondition::Damaged ? $quantity : '0.000000',
            self::QUANTITY_SCALE,
        );

        if (
            bccomp($this->baseDecimal($command->onHandBaseQuantityDelta), $expectedOnHandDelta, self::QUANTITY_SCALE) !== 0
            || bccomp($this->baseDecimal($command->damagedBaseQuantityDelta), $expectedDamagedDelta, self::QUANTITY_SCALE) !== 0
        ) {
            throw new DomainException('Condition transfer deltas do not reconcile with the compatibility stock mutation.');
        }
    }

    private function assertSerializedTransition(InventoryPostingCommand $command): void
    {
        $hasTransition = $command->serializedTargetStatus instanceof SerializedInventoryUnitStatus
            || $command->serializedWarehouseSpecified
            || $command->serializedTargetCustodyType instanceof SerializedCustodyType
            || $command->serializedTargetCustodyReferenceType !== null
            || $command->serializedTargetCustodyReferenceId !== null
            || $command->serializedTargetStockCondition instanceof StockCondition
            || $command->serializedInventoryLotSpecified;

        if ($hasTransition && $command->serializedInventoryUnitId === null) {
            throw new DomainException('Serialized inventory state changes require a serialized inventory unit identifier.');
        }

        if (($command->serializedTargetCustodyReferenceType === null) !== ($command->serializedTargetCustodyReferenceId === null)) {
            throw new DomainException('Serialized custody references must provide both type and identifier.');
        }

        if (
            $command->serializedInventoryLotSpecified
            && $command->serializedTargetInventoryLotId !== null
            && $command->serializedTargetInventoryLotId !== $command->inventoryLotId
        ) {
            throw new DomainException('A serialized posting cannot target a different lot than its tracked lot allocation.');
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
        $hasSerializedTransition = $command->serializedTargetStatus instanceof SerializedInventoryUnitStatus
            || $command->serializedWarehouseSpecified
            || $command->serializedTargetCustodyType instanceof SerializedCustodyType
            || $command->serializedTargetCustodyReferenceType !== null
            || $command->serializedTargetCustodyReferenceId !== null
            || $command->serializedTargetStockCondition instanceof StockCondition
            || $command->serializedInventoryLotSpecified;
        $hasConditionTransfer = $command->conditionTransferBaseQuantity !== null
            && bccomp(
                $this->baseDecimal($command->conditionTransferBaseQuantity),
                '0',
                self::QUANTITY_SCALE,
            ) !== 0;

        if ($command->evidenceOnly && (
            bccomp($onHandDelta, '0', self::QUANTITY_SCALE) !== 0
            || bccomp($reservedDelta, '0', self::QUANTITY_SCALE) !== 0
            || bccomp($damagedDelta, '0', self::QUANTITY_SCALE) !== 0
            || bccomp($lotOnHandDelta, '0', self::QUANTITY_SCALE) !== 0
            || bccomp($lotReservedDelta, '0', self::QUANTITY_SCALE) !== 0
            || $hasSerializedTransition
            || $hasConditionTransfer
        )) {
            throw new DomainException('Evidence-only inventory postings must have zero materialized deltas.');
        }

        if (
            bccomp($onHandDelta, '0', self::QUANTITY_SCALE) === 0
            && bccomp($reservedDelta, '0', self::QUANTITY_SCALE) === 0
            && bccomp($damagedDelta, '0', self::QUANTITY_SCALE) === 0
            && bccomp($lotOnHandDelta, '0', self::QUANTITY_SCALE) === 0
            && bccomp($lotReservedDelta, '0', self::QUANTITY_SCALE) === 0
            && ! $hasSerializedTransition
            && ! $hasConditionTransfer
            && ! $command->evidenceOnly
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

    /** @return list<StockCondition> */
    private function materializedConditions(): array
    {
        return [
            StockCondition::Saleable,
            StockCondition::Quarantine,
            StockCondition::Damaged,
        ];
    }

    private function conditionKey(int $variantId, int $warehouseId, StockCondition $condition): string
    {
        return $variantId.':'.$warehouseId.':'.$condition->value;
    }

    private function lotConditionKey(int $lotId, int $warehouseId, StockCondition $condition): string
    {
        return $lotId.':'.$warehouseId.':'.$condition->value;
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

    private function assertReversalReference(InventoryPostingCommand $command): void
    {
        if ($command->reversalOfMovementId === null) {
            return;
        }

        $original = InventoryMovement::query()->find($command->reversalOfMovementId);

        if (! $original instanceof InventoryMovement) {
            throw new DomainException('A compensating inventory movement must reference an existing original movement.');
        }

        if (
            $original->product_variant_id !== $command->productVariantId
            || $original->warehouse_id !== $command->warehouseId
        ) {
            throw new DomainException(
                'A compensating inventory movement must reverse the same variant and warehouse as its original movement.',
            );
        }

        $originalQuantity = $this->baseDecimal((string) ($original->base_quantity_delta ?? $original->quantity));
        $compensatingQuantity = $this->baseDecimal(
            $command->baseQuantityDelta ?? $command->movementBaseQuantityDelta,
        );

        if (
            bccomp($originalQuantity, '0', self::QUANTITY_SCALE) !== 0
            && bccomp($compensatingQuantity, '0', self::QUANTITY_SCALE) !== 0
            && (
                (bccomp($originalQuantity, '0', self::QUANTITY_SCALE) === 1
                    && bccomp($compensatingQuantity, '0', self::QUANTITY_SCALE) === 1)
                || (bccomp($originalQuantity, '0', self::QUANTITY_SCALE) === -1
                    && bccomp($compensatingQuantity, '0', self::QUANTITY_SCALE) === -1)
            )
        ) {
            throw new DomainException(
                'A compensating inventory movement must have the opposite quantity direction from its original movement.',
            );
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
            if (bccomp(
                $this->baseDecimal($command->movementBaseQuantityDelta),
                '0',
                self::QUANTITY_SCALE,
            ) !== 0) {
                throw new DomainException(
                    'New physical inventory postings require complete transaction-UOM snapshots.',
                );
            }

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
        $absoluteBaseQuantity = bccomp($baseQuantityDelta, '0', self::QUANTITY_SCALE) < 0
            ? bcsub('0', $baseQuantityDelta, self::QUANTITY_SCALE)
            : $baseQuantityDelta;
        $expectedBaseQuantity = bcadd(
            bcmul($transactionQuantity, $conversionFactor, 12),
            '0',
            self::QUANTITY_SCALE,
        );

        if (
            bccomp($transactionQuantity, '0', self::QUANTITY_SCALE) <= 0
            || bccomp($conversionFactor, '0', self::QUANTITY_SCALE) <= 0
            || bccomp($baseQuantityDelta, $movementQuantity, self::QUANTITY_SCALE) !== 0
            || bccomp($absoluteBaseQuantity, $expectedBaseQuantity, self::QUANTITY_SCALE) !== 0
        ) {
            throw new DomainException('The inventory posting transaction-UOM snapshot is invalid.');
        }
    }
}
