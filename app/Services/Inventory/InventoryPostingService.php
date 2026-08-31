<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryBalanceSnapshot;
use App\Data\Inventory\InventoryPostingCommand;
use App\Data\Inventory\InventoryPostingResult;
use App\Enums\MovementType;
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
    public function __construct(private InventoryBalanceService $inventoryBalanceService) {}

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
        $serializedUnits = $this->serializedUnitsForUpdate($newCommands);

        foreach ($newCommands as $command) {
            $postingResults[spl_object_id($command)] = $this->postNewCommand($command, $stocks, $serializedUnits);
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
        array $serializedUnits,
    ): InventoryPostingResult {
        $existingPosting = $this->idempotentPostingResult($command);

        if ($existingPosting instanceof InventoryPostingResult) {
            return $existingPosting;
        }

        return $this->createPostingResult($command, $stocks[$this->stockKey($command)], $serializedUnits);
    }

    /**
     * @param  array<int, SerializedInventoryUnit>  $serializedUnits
     */
    private function createPostingResult(
        InventoryPostingCommand $command,
        InventoryStock $stock,
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
            'idempotency_key' => $command->idempotencyKey,
            'serialized_inventory_unit_id' => $command->serializedInventoryUnitId,
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
            || bccomp($movementQuantity, $commandQuantity, 3) !== 0
            || $movement->source_type !== $command->sourceType
            || ! $this->matchesRequiredId($movement->source_id, $command->sourceId)
            || ! $this->matchesNullableId($movement->serialized_inventory_unit_id, $command->serializedInventoryUnitId)
            || ! $this->matchesRequiredId($movement->created_by, $command->actorId)
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
        $this->assertMaterializedBalanceChange($command);
    }

    private function assertCommandIdentifiers(InventoryPostingCommand $command): void
    {
        if ($command->productVariantId <= 0 || $command->warehouseId <= 0 || $command->sourceId <= 0 || $command->actorId <= 0) {
            throw new DomainException('Inventory posting identifiers must be positive integers.');
        }

        if ($command->serializedInventoryUnitId !== null && $command->serializedInventoryUnitId <= 0) {
            throw new DomainException('Serialized inventory unit identifiers must be positive integers.');
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

    private function assertMaterializedBalanceChange(InventoryPostingCommand $command): void
    {

        $onHandDelta = $this->baseDecimal($command->onHandBaseQuantityDelta);
        $reservedDelta = $this->baseDecimal($command->reservedBaseQuantityDelta);
        $damagedDelta = $this->baseDecimal($command->damagedBaseQuantityDelta);
        $this->baseDecimal($command->movementBaseQuantityDelta);

        if (
            bccomp($onHandDelta, '0', 3) === 0
            && bccomp($reservedDelta, '0', 3) === 0
            && bccomp($damagedDelta, '0', 3) === 0
        ) {
            throw new DomainException('An inventory posting must change a materialized balance.');
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
        $availableQuantity = bcsub(bcsub($onHandQuantity, $reservedQuantity, 3), $damagedQuantity, 3);

        if (bccomp($availableQuantity, $damagedDelta, 3) < 0) {
            throw new DomainException(__('admin.inventory.balance.errors.insufficient_available'));
        }
    }

    /**
     * @param  numeric-string  $damagedQuantity
     * @param  numeric-string  $damagedDelta
     */
    private function assertDamagedQuantityIsAvailable(string $damagedQuantity, string $damagedDelta): void
    {
        if (bccomp($damagedQuantity, bcsub('0', $damagedDelta, 3), 3) < 0) {
            throw new DomainException(__('admin.inventory.balance.errors.insufficient_damaged'));
        }
    }

    /** @return numeric-string */
    private function baseDecimal(string $quantity): string
    {
        if (! is_numeric($quantity) || preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d{1,3})?$/D', $quantity) !== 1) {
            throw new DomainException('Inventory posting quantities must be exact base-UOM decimal strings with at most three places.');
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
}
