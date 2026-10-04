<?php

declare(strict_types=1);

use App\Enums\MovementType;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Services\Inventory\InventoryEquipmentLoanService;
use App\Services\Inventory\InventorySupplierCustodyService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContinuityFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ContinuityFixtures::seedPermissions();
});

function stockOf(int $variantId, int $warehouseId): InventoryStock
{
    return InventoryStock::query()->where('product_variant_id', $variantId)->where('warehouse_id', $warehouseId)->firstOrFail();
}

it('issues a warehouse unit into customer temporary custody through an inventory movement', function (): void {
    $unit = ContinuityFixtures::warehouseUnit();
    $warehouseId = $unit->warehouse_id;
    [$customer] = ContinuityFixtures::repairScenario();

    $movement = app(InventoryEquipmentLoanService::class)->issue($unit, $customer->id, 41, ContinuityFixtures::operator(), 'Loan for repair');

    $unit->refresh();

    expect($movement->movement_type)->toBe(MovementType::LoanIssue)
        ->and($movement->source_type)->toBe('equipment_loan')
        ->and($movement->source_id)->toBe(41)
        ->and($movement->serialized_inventory_unit_id)->toBe($unit->id)
        ->and($unit->custody_type)->toBe(SerializedCustodyType::Customer)
        ->and((int) $unit->custody_reference_id)->toBe($customer->id)
        ->and($unit->status)->toBe(SerializedInventoryUnitStatus::Delivered)
        ->and($unit->warehouse_id)->toBeNull()
        ->and((float) stockOf($unit->product_variant_id, $warehouseId)->on_hand_quantity)->toBe(0.0);
});

it('refuses to issue a unit that is not available, saleable and warehouse-held, or is lot-tracked', function (): void {
    $service = app(InventoryEquipmentLoanService::class);
    $operator = ContinuityFixtures::operator();

    foreach ([
        ['status', SerializedInventoryUnitStatus::Damaged],
        ['custody_type', SerializedCustodyType::Customer],
        ['stock_condition', StockCondition::Quarantine],
        ['warehouse_id', null],
    ] as [$field, $value]) {
        $unit = ContinuityFixtures::warehouseUnit();
        $unit->forceFill([$field => $value])->save();

        expect(fn () => $service->issue($unit, 1, 1, $operator))->toThrow(DomainException::class, 'available, saleable, warehouse-held');
    }

    $lotted = ContinuityFixtures::warehouseUnit();
    $lotted->forceFill(['inventory_lot_id' => InventoryLot::factory()->create(['product_variant_id' => $lotted->product_variant_id])->id])->save();

    expect(fn () => $service->issue($lotted, 1, 2, $operator))->toThrow(DomainException::class, 'Lot-tracked');
});

it('requires the inventory loan permission and never lets Support permission alone move custody', function (): void {
    $unit = ContinuityFixtures::warehouseUnit();

    expect(fn () => app(InventoryEquipmentLoanService::class)->issue($unit, 1, 1, ContinuityFixtures::supportOnly()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(InventoryEquipmentLoanService::class)->return($unit, 1, StockCondition::Saleable, 1, ContinuityFixtures::supportOnly()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(InventorySupplierCustodyService::class)->shipToSupplier($unit, 1, 1, ContinuityFixtures::supportOnly()))->toThrow(AuthorizationException::class)
        ->and($unit->fresh()->custody_type)->toBe(SerializedCustodyType::Warehouse);
});

it('returns a loaner to the warehouse in the inspected condition', function (): void {
    $operator = ContinuityFixtures::operator();
    $service = app(InventoryEquipmentLoanService::class);
    [$customer] = ContinuityFixtures::repairScenario();

    foreach ([
        [StockCondition::Saleable, SerializedInventoryUnitStatus::Available, 0.0],
        [StockCondition::Damaged, SerializedInventoryUnitStatus::Damaged, 1.0],
        [StockCondition::Quarantine, SerializedInventoryUnitStatus::Available, 0.0],
    ] as $index => [$condition, $status, $damaged]) {
        $unit = ContinuityFixtures::warehouseUnit();
        $service->issue($unit, $customer->id, 100 + $index, $operator);
        $warehouse = ContinuityFixtures::warehouse();

        $movement = $service->return($unit, $warehouse->id, $condition, 100 + $index, $operator);
        $unit->refresh();

        expect($movement->movement_type)->toBe(MovementType::LoanReturn)
            ->and($unit->custody_type)->toBe(SerializedCustodyType::Warehouse)
            ->and($unit->warehouse_id)->toBe($warehouse->id)
            ->and($unit->status)->toBe($status)
            ->and($unit->stock_condition)->toBe($condition)
            ->and((float) stockOf($unit->product_variant_id, $warehouse->id)->on_hand_quantity)->toBe(1.0)
            ->and((float) stockOf($unit->product_variant_id, $warehouse->id)->damaged_quantity)->toBe($damaged);
    }
});

it('rejects returns for units that are not out on loan and for disposed conditions', function (): void {
    $service = app(InventoryEquipmentLoanService::class);
    $operator = ContinuityFixtures::operator();
    $unit = ContinuityFixtures::warehouseUnit();

    expect(fn () => $service->return($unit, $unit->warehouse_id, StockCondition::Saleable, 1, $operator))->toThrow(DomainException::class, 'customer custody')
        ->and(fn () => $service->return($unit, $unit->warehouse_id, StockCondition::Disposed, 1, $operator))->toThrow(DomainException::class, 'inspected');
});

it('is idempotent per loan: a second issue of the same loan posts nothing new', function (): void {
    $operator = ContinuityFixtures::operator();
    $service = app(InventoryEquipmentLoanService::class);
    $unit = ContinuityFixtures::warehouseUnit();

    $first = $service->issue($unit, 1, 7, $operator);

    expect(fn () => $service->issue($unit, 1, 7, $operator))->toThrow(DomainException::class)
        ->and(InventoryMovement::query()->where('source_type', 'equipment_loan')->where('source_id', 7)->count())->toBe(1)
        ->and($first->id)->not->toBeNull();
});

it('ships a warehouse unit to supplier custody and receives it back', function (): void {
    $operator = ContinuityFixtures::operator();
    $service = app(InventorySupplierCustodyService::class);

    foreach ([StockCondition::Saleable, StockCondition::Damaged] as $index => $condition) {
        $unit = ContinuityFixtures::warehouseUnit(condition: $condition);
        $warehouseId = $unit->warehouse_id;

        $out = $service->shipToSupplier($unit, 9, 50 + $index, $operator);
        $unit->refresh();

        expect($out->movement_type)->toBe(MovementType::SupplierRepairOut)
            ->and($unit->custody_type)->toBe(SerializedCustodyType::Supplier)
            ->and($unit->status)->toBe(SerializedInventoryUnitStatus::ReturnedToSupplier)
            ->and($unit->warehouse_id)->toBeNull()
            ->and((int) $unit->custody_reference_id)->toBe(9)
            ->and((float) stockOf($unit->product_variant_id, $warehouseId)->on_hand_quantity)->toBe(0.0);

        $in = $service->receiveFromSupplier($unit, $warehouseId, StockCondition::Saleable, 50 + $index, $operator);
        $unit->refresh();

        expect($in->movement_type)->toBe(MovementType::SupplierRepairIn)
            ->and($unit->custody_type)->toBe(SerializedCustodyType::Warehouse)
            ->and($unit->status)->toBe(SerializedInventoryUnitStatus::Available)
            ->and($unit->stock_condition)->toBe(StockCondition::Saleable);
    }
});

it('only ships warehouse-held units and only receives units that are with a supplier', function (): void {
    $operator = ContinuityFixtures::operator();
    $service = app(InventorySupplierCustodyService::class);
    [, $customerUnit] = ContinuityFixtures::repairScenario();
    $warehouseUnit = ContinuityFixtures::warehouseUnit();
    $lotted = ContinuityFixtures::warehouseUnit();
    $lotted->forceFill(['inventory_lot_id' => InventoryLot::factory()->create(['product_variant_id' => $lotted->product_variant_id])->id])->save();

    expect(fn () => $service->shipToSupplier($customerUnit, 1, 1, $operator))->toThrow(DomainException::class, 'receive it into the warehouse first')
        ->and(fn () => $service->shipToSupplier($lotted, 1, 2, $operator))->toThrow(DomainException::class, 'Lot-tracked')
        ->and(fn () => $service->receiveFromSupplier($warehouseUnit, $warehouseUnit->warehouse_id, StockCondition::Saleable, 3, $operator))->toThrow(DomainException::class, 'supplier custody')
        ->and(fn () => $service->receiveFromSupplier($warehouseUnit, $warehouseUnit->warehouse_id, StockCondition::Disposed, 3, $operator))->toThrow(DomainException::class, 'inspected');
});
