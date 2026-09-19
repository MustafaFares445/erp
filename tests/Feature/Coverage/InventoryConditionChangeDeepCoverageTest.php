<?php

declare(strict_types=1);

use App\Data\Inventory\DamageDraftData;
use App\Data\Inventory\DisposalDraftData;
use App\Data\Inventory\QuarantineDispositionData;
use App\Enums\ConditionChangeReason;
use App\Enums\InventoryConditionChangeStatus;
use App\Enums\InventoryConditionChangeType;
use App\Enums\MovementType;
use App\Enums\QuarantineDisposition;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Exceptions\Domain\QuarantineDispositionRejected;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryConditionChange;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryConditionChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

function conditionCoverageInvoke(string $method, mixed ...$arguments): mixed
{
    return new ReflectionMethod(InventoryConditionChangeService::class, $method)
        ->invoke(app(InventoryConditionChangeService::class), ...$arguments);
}

function conditionCoverageChange(array $overrides = []): InventoryConditionChange
{
    static $sequence = 0;
    $sequence++;

    $variant = $overrides['product_variant'] ?? ProductVariant::factory()->create();
    $warehouse = $overrides['warehouse'] ?? Warehouse::factory()->create();
    $actor = $overrides['actor'] ?? User::factory()->create();

    unset($overrides['product_variant'], $overrides['warehouse'], $overrides['actor']);

    return InventoryConditionChange::query()->forceCreate([
        'document_number' => 'ICC-COV-'.mb_str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
        'type' => InventoryConditionChangeType::QuarantineDisposition,
        'status' => InventoryConditionChangeStatus::Draft,
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'condition_from' => StockCondition::Quarantine,
        'condition_to' => StockCondition::Saleable,
        'base_quantity' => '1.000000',
        'disposition' => QuarantineDisposition::ReleaseToSaleable,
        'reason_category' => ConditionChangeReason::Other,
        'reason' => 'Coverage reason.',
        'created_by' => $actor->getKey(),
        ...$overrides,
    ]);
}
it('covers quarantine and damage draft validation guards', function (): void {
    $service = app(InventoryConditionChangeService::class);
    $actor = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $inactive = Warehouse::factory()->create(['is_active' => false]);

    expect(fn () => $service->draftQuarantineDisposition(new QuarantineDispositionData(
        productVariantId: (int) $variant->getKey(),
        warehouseId: (int) $warehouse->getKey(),
        inventoryLotId: null,
        serializedInventoryUnitId: null,
        baseQuantity: '1',
        disposition: QuarantineDisposition::ReleaseToSaleable,
        reasonCategory: ConditionChangeReason::Other,
        reason: '   ',
    ), $actor))->toThrow(QuarantineDispositionRejected::class, 'reason is required');

    expect(fn () => $service->draftQuarantineDisposition(new QuarantineDispositionData(
        productVariantId: (int) $variant->getKey(),
        warehouseId: (int) $inactive->getKey(),
        inventoryLotId: null,
        serializedInventoryUnitId: null,
        baseQuantity: '1',
        disposition: QuarantineDisposition::ReleaseToSaleable,
        reasonCategory: ConditionChangeReason::Other,
        reason: 'Inactive warehouse.',
    ), $actor))->toThrow(QuarantineDispositionRejected::class, 'warehouse is inactive');

    expect(fn () => $service->draftDamage(new DamageDraftData(
        productVariantId: (int) $variant->getKey(),
        warehouseId: (int) $inactive->getKey(),
        inventoryLotId: null,
        serializedInventoryUnitId: null,
        baseQuantity: '1',
        reasonCategory: ConditionChangeReason::Other,
        reason: 'Damage coverage.',
    ), $actor))->toThrow(DomainException::class);
});
it('covers disposal authoriser and inactive warehouse guards', function (): void {
    $service = app(InventoryConditionChangeService::class);
    $actor = User::factory()->create();
    $other = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $inactive = Warehouse::factory()->create(['is_active' => false]);

    expect(fn () => $service->draftDisposal(new DisposalDraftData(
        productVariantId: (int) $variant->getKey(),
        warehouseId: (int) $inactive->getKey(),
        inventoryLotId: null,
        serializedInventoryUnitId: null,
        baseQuantity: '1',
        reasonCategory: ConditionChangeReason::Other,
        reason: 'Dispose coverage.',
        authorisedBy: (int) $other->getKey(),
    ), $actor))->toThrow(DomainException::class);

    expect(fn () => $service->draftDisposal(new DisposalDraftData(
        productVariantId: (int) $variant->getKey(),
        warehouseId: (int) $warehouse->getKey(),
        inventoryLotId: null,
        serializedInventoryUnitId: null,
        baseQuantity: '1',
        reasonCategory: ConditionChangeReason::Other,
        reason: 'Missing authoriser.',
        authorisedBy: 999999,
    ), $actor))->toThrow(DomainException::class);
});
it('covers posting and cancellation document guards', function (): void {
    $service = app(InventoryConditionChangeService::class);
    $actor = User::factory()->create();

    $wrongDocument = conditionCoverageChange([
        'condition_from' => StockCondition::Saleable,
    ]);
    expect(fn () => $service->post($wrongDocument, $actor))
        ->toThrow(QuarantineDispositionRejected::class, 'not a quarantine disposition');

    $inactive = Warehouse::factory()->create(['is_active' => false]);
    $inactiveDocument = conditionCoverageChange(['warehouse' => $inactive]);
    expect(fn () => $service->post($inactiveDocument, $actor))
        ->toThrow(QuarantineDispositionRejected::class, 'warehouse is inactive');

    $cancel = conditionCoverageChange();
    expect(fn () => $service->cancel($cancel, $actor, '   '))
        ->toThrow(QuarantineDispositionRejected::class, 'cancellation reason is required');
});
it('covers draft tracking shape guards for batch and serialized variants', function (): void {
    $grain = ProductVariant::factory()->grain()->create()->load('product');
    $machine = ProductVariant::factory()->machine()->create()->load('product');

    expect(fn (): mixed => conditionCoverageInvoke('validateDraftTrackingShape', $grain, null, null, '1.000000'))
        ->toThrow(DomainException::class)
        ->and(fn (): mixed => conditionCoverageInvoke('validateDraftTrackingShape', $machine, null, null, '1.000000'))
        ->toThrow(DomainException::class);
});
it('covers quarantine tracking identity lot serial and aggregate guards', function (): void {
    $warehouse = Warehouse::factory()->create();
    $grain = ProductVariant::factory()->grain()->create()->load('product');
    $machine = ProductVariant::factory()->machine()->create()->load('product');

    expect(fn (): mixed => conditionCoverageInvoke(
        'validateTrackingIdentity',
        $grain,
        $warehouse,
        '1.000000',
        null,
        null,
        false,
    ))->toThrow(QuarantineDispositionRejected::class, 'lot is required');

    expect(fn (): mixed => conditionCoverageInvoke(
        'validateTrackingIdentity',
        $machine,
        $warehouse,
        '1.000000',
        null,
        null,
        false,
    ))->toThrow(QuarantineDispositionRejected::class, 'serialized unit is required');

    $wrongLot = InventoryLot::factory()->canonical()->create([
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
    ]);
    expect(fn (): mixed => conditionCoverageInvoke(
        'validateTrackingIdentity',
        $grain,
        $warehouse,
        '1.000000',
        (int) $wrongLot->getKey(),
        null,
        false,
    ))->toThrow(QuarantineDispositionRejected::class, 'lot does not match');

    $lot = InventoryLot::factory()->canonical()->create([
        'product_variant_id' => $grain->getKey(),
    ]);
    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $lot->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Quarantine,
        'on_hand_base_quantity' => '0.500000',
        'reserved_base_quantity' => '0.000000',
    ]);
    expect(fn (): mixed => conditionCoverageInvoke(
        'validateTrackingIdentity',
        $grain,
        $warehouse,
        '1.000000',
        (int) $lot->getKey(),
        null,
        true,
    ))->toThrow(QuarantineDispositionRejected::class, 'lot does not contain enough');

    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $machine->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'status' => SerializedInventoryUnitStatus::Delivered,
        'custody_type' => SerializedCustodyType::Customer,
        'stock_condition' => StockCondition::Saleable,
    ]);
    expect(fn (): mixed => conditionCoverageInvoke(
        'validateTrackingIdentity',
        $machine,
        $warehouse,
        '1.000000',
        null,
        (int) $unit->getKey(),
        false,
    ))->toThrow(QuarantineDispositionRejected::class, 'not quarantined');

    InventoryLotBalance::query()
        ->where('inventory_lot_id', $lot->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->where('stock_condition', StockCondition::Quarantine->value)
        ->update(['on_hand_base_quantity' => '2.000000']);

    InventoryConditionBalance::query()->forceCreate([
        'product_variant_id' => $grain->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Quarantine,
        'on_hand_base_quantity' => '0.250000',
        'reserved_base_quantity' => '0.000000',
    ]);
    expect(fn (): mixed => conditionCoverageInvoke(
        'validateTrackingIdentity',
        $grain,
        $warehouse,
        '1.000000',
        (int) $lot->getKey(),
        null,
        true,
    ))->toThrow(QuarantineDispositionRejected::class, 'warehouse does not contain enough');
});
it('covers recovery quantity scalar quantity and identifier guards', function (): void {
    $damage = conditionCoverageChange([
        'type' => InventoryConditionChangeType::Damage,
        'status' => InventoryConditionChangeStatus::Posted,
        'condition_from' => StockCondition::Saleable,
        'condition_to' => StockCondition::Damaged,
        'base_quantity' => '1.000000',
        'disposition' => null,
    ]);
    $recovery = conditionCoverageChange([
        'type' => InventoryConditionChangeType::DamageRecovery,
        'reverses_condition_change_id' => $damage->getKey(),
        'condition_from' => StockCondition::Damaged,
        'condition_to' => StockCondition::Saleable,
        'base_quantity' => '2.000000',
        'disposition' => null,
    ]);

    expect(fn (): mixed => conditionCoverageInvoke('assertRecoveryWithinDamagedQuantity', $recovery))
        ->toThrow(DomainException::class)
        ->and(fn (): mixed => conditionCoverageInvoke('positiveQuantity', '0'))
        ->toThrow(QuarantineDispositionRejected::class, 'quantity must be positive')
        ->and(fn (): mixed => conditionCoverageInvoke('integerKey', new stdClass, 'coverage object'))
        ->toThrow(LogicException::class, 'must be an Eloquent model');
});
it('covers serialized target statuses for quarantine posting commands', function (
    QuarantineDisposition $disposition,
    SerializedInventoryUnitStatus $expectedStatus,
): void {
    $actor = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
        'custody_type' => SerializedCustodyType::Warehouse,
        'stock_condition' => StockCondition::Quarantine,
    ]);
    $change = conditionCoverageChange([
        'product_variant' => $variant,
        'warehouse' => $warehouse,
        'actor' => $actor,
        'serialized_inventory_unit_id' => $unit->getKey(),
        'disposition' => $disposition,
        'condition_to' => $disposition->conditionTo(),
    ]);

    $command = conditionCoverageInvoke('postingCommand', $change, $variant, $actor, '1.000000', $unit);

    expect($command->serializedTargetStatus)->toBe($expectedStatus);
})->with([
    'release' => [QuarantineDisposition::ReleaseToSaleable, SerializedInventoryUnitStatus::Available],
    'damage' => [QuarantineDisposition::DowngradeToDamaged, SerializedInventoryUnitStatus::Damaged],
    'dispose' => [QuarantineDisposition::Dispose, SerializedInventoryUnitStatus::Disposed],
]);
it('covers invalid supplier receipt provenance resolution', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $delivery = InventoryOperation::factory()->delivery()->done()->create();
    InventoryMovement::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'movement_type' => MovementType::Receipt,
        'source_type' => 'inventory_operation',
        'source_id' => $delivery->getKey(),
        'stock_condition_to' => StockCondition::Quarantine,
    ]);

    $change = conditionCoverageChange([
        'product_variant' => $variant,
        'warehouse' => $warehouse,
    ]);

    expect(fn (): mixed => conditionCoverageInvoke('resolveSupplierProvenance', $change, null, null))
        ->toThrow(QuarantineDispositionRejected::class, 'completed supplier receipt');
});
