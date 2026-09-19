<?php

declare(strict_types=1);

use App\Data\Inventory\InventoryPostingCommand;
use App\Enums\MovementType;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\InventoryStock;
use App\Services\Inventory\InventoryPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function postingCoverageInvoke(string $method, mixed ...$arguments): mixed
{
    return new ReflectionMethod(InventoryPostingService::class, $method)
        ->invoke(app(InventoryPostingService::class), ...$arguments);
}

/** @param array<string,mixed> $overrides */
function postingCoverageCommand(array $overrides = []): InventoryPostingCommand
{
    return new InventoryPostingCommand(...[
        'productVariantId' => 1,
        'warehouseId' => 1,
        'onHandBaseQuantityDelta' => '1.000000',
        'reservedBaseQuantityDelta' => '0.000000',
        'damagedBaseQuantityDelta' => '0.000000',
        'movementType' => MovementType::Receipt,
        'movementBaseQuantityDelta' => '1.000000',
        'sourceType' => 'coverage',
        'sourceId' => 1,
        'actorId' => 1,
        'transactionQuantity' => '1.000000',
        'transactionUnitId' => 1,
        'conversionFactorSnapshot' => '1.000000',
        'baseQuantityDelta' => '1.000000',
        ...$overrides,
    ]);
}
it('covers posting identifier source and idempotency guards', function (): void {
    expect(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand(['productVariantId' => 0])))
        ->toThrow(DomainException::class, 'positive integers')
        ->and(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand(['actorId' => -1])))
        ->toThrow(DomainException::class, 'positive integers')
        ->and(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand(['sourceType' => '   '])))
        ->toThrow(DomainException::class, 'source document reference')
        ->and(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand(['idempotencyKey' => '   '])))
        ->toThrow(DomainException::class, 'idempotency keys')
        ->and(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand(['idempotencyKey' => str_repeat('x', 192)])))
        ->toThrow(DomainException::class, 'idempotency keys')
        ->and(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand(['sourceLineType' => 'line', 'sourceLineId' => null])))
        ->toThrow(DomainException::class, 'complete source-line references');
});
it('covers posting quantity snapshot and lot mutation guards', function (): void {
    expect(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand([
        'transactionQuantity' => null,
        'transactionUnitId' => null,
        'conversionFactorSnapshot' => null,
        'baseQuantityDelta' => null,
    ])))->toThrow(DomainException::class, 'complete transaction-UOM snapshots')
        ->and(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand([
            'transactionQuantity' => '1',
            'transactionUnitId' => null,
        ])))->toThrow(DomainException::class, 'complete transaction-UOM snapshots')
        ->and(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand([
            'transactionQuantity' => '2',
            'baseQuantityDelta' => '1',
        ])))->toThrow(DomainException::class, 'snapshot is invalid')
        ->and(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand([
            'lotOnHandBaseQuantityDelta' => '1',
            'inventoryLotId' => null,
        ])))->toThrow(DomainException::class, 'inventory lot identifier')
        ->and(fn (): mixed => postingCoverageInvoke('baseDecimal', '1.1234567'))
        ->toThrow(DomainException::class, 'exact base-UOM decimal');
});
it('covers physical condition mutation guards', function (): void {
    expect(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand([
        'stockCondition' => StockCondition::Disposed,
    ])))->toThrow(DomainException::class, 'Disposed stock')
        ->and(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand([
            'stockCondition' => StockCondition::Quarantine,
            'reservedBaseQuantityDelta' => '1',
        ])))->toThrow(DomainException::class, 'Only saleable stock')
        ->and(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand([
            'stockCondition' => StockCondition::Damaged,
            'onHandBaseQuantityDelta' => '1',
            'damagedBaseQuantityDelta' => '0',
        ])))->toThrow(DomainException::class, 'mirror the damaged')
        ->and(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand([
            'stockCondition' => StockCondition::Saleable,
            'damagedBaseQuantityDelta' => '1',
        ])))->toThrow(DomainException::class, 'cannot mutate damaged');
});
it('covers condition transfer validation branches', function (): void {
    expect(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand([
        'conditionFrom' => StockCondition::Saleable,
    ])))->toThrow(DomainException::class, 'require from, to, and base quantity')
        ->and(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand([
            'conditionFrom' => StockCondition::Disposed,
            'conditionTo' => StockCondition::Saleable,
            'conditionTransferBaseQuantity' => '1',
        ])))->toThrow(DomainException::class, 'originate from a materialized')
        ->and(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand([
            'conditionFrom' => StockCondition::Saleable,
            'conditionTo' => StockCondition::Quarantine,
            'conditionTransferBaseQuantity' => '0',
        ])))->toThrow(DomainException::class, 'quantity must be positive')
        ->and(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand([
            'conditionFrom' => StockCondition::Saleable,
            'conditionTo' => StockCondition::Quarantine,
            'conditionTransferBaseQuantity' => '1',
            'reservedBaseQuantityDelta' => '1',
        ])))->toThrow(DomainException::class, 'cannot carry reservation')
        ->and(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand([
            'conditionFrom' => StockCondition::Saleable,
            'conditionTo' => StockCondition::Quarantine,
            'conditionTransferBaseQuantity' => '2',
        ])))->toThrow(DomainException::class, 'full base quantity');
});
it('covers serialized transition guards', function (): void {
    expect(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand([
        'serializedTargetStatus' => SerializedInventoryUnitStatus::Available,
    ])))->toThrow(DomainException::class, 'require a serialized inventory unit identifier')
        ->and(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand([
            'serializedInventoryUnitId' => 1,
            'serializedTargetCustodyType' => SerializedCustodyType::Warehouse,
            'serializedTargetCustodyReferenceType' => 'warehouse',
            'serializedTargetCustodyReferenceId' => null,
        ])))->toThrow(DomainException::class, 'provide both type and identifier')
        ->and(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand([
            'inventoryLotId' => 1,
            'serializedInventoryUnitId' => 1,
            'serializedInventoryLotSpecified' => true,
            'serializedTargetInventoryLotId' => 2,
        ])))->toThrow(DomainException::class, 'cannot target a different lot');
});
it('covers evidence only and no-op posting guards', function (): void {
    expect(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand([
        'evidenceOnly' => true,
    ])))->toThrow(DomainException::class, 'Evidence-only')
        ->and(fn (): mixed => postingCoverageInvoke('validateCommand', postingCoverageCommand([
            'onHandBaseQuantityDelta' => '0',
            'movementBaseQuantityDelta' => '0',
            'transactionQuantity' => null,
            'transactionUnitId' => null,
            'conversionFactorSnapshot' => null,
            'baseQuantityDelta' => null,
        ])))->toThrow(DomainException::class, 'must change stock');
});
it('covers duplicate idempotency and availability helpers', function (): void {
    $a = postingCoverageCommand(['idempotencyKey' => 'duplicate']);
    $b = postingCoverageCommand(['idempotencyKey' => 'duplicate']);

    expect(fn (): mixed => postingCoverageInvoke('orderedCommands', [$a, $b]))
        ->toThrow(DomainException::class, 'same idempotency key twice')
        ->and(fn (): mixed => postingCoverageInvoke('assertDamageQuantityIsAvailable', '2', '1', '1', '1'))
        ->toThrow(DomainException::class)
        ->and(fn (): mixed => postingCoverageInvoke('assertDamagedQuantityIsAvailable', '1', '-2'))
        ->toThrow(DomainException::class);

    $stock = new InventoryStock;
    $stock->forceFill([
        'on_hand_quantity' => '1',
        'reserved_quantity' => '0',
        'damaged_quantity' => '0',
    ]);
    expect(fn (): mixed => postingCoverageInvoke('initialConditionBalance', $stock, StockCondition::Disposed))
        ->toThrow(DomainException::class, 'not a materialized warehouse balance');
});
