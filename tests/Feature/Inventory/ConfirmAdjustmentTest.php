<?php

declare(strict_types=1);

use App\Enums\AdjustmentStatus;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\AuditLog;
use App\Models\InventoryAdjustment;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryLotBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Package;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function confirmService(): InventoryAdjustmentService
{
    return app(InventoryAdjustmentService::class);
}

it('confirms an adjustment, changing balances by exactly the line differences and writing one movement per line', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variantA = ProductVariant::factory()->create();
    $variantB = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variantA)->for($warehouse)->create(['on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'available_quantity' => '10.000']);
    InventoryStock::factory()->for($variantB)->for($warehouse)->create(['on_hand_quantity' => '5.000', 'reserved_quantity' => '0.000', 'available_quantity' => '5.000']);

    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create(['product_variant_id' => $variantA->id, 'new_quantity' => '13.000']);
    $adjustment->items()->create(['product_variant_id' => $variantB->id, 'new_quantity' => '2.000']);

    $actor = User::factory()->create();

    confirmService()->confirm($adjustment, $actor);

    $adjustment->refresh();

    expect($adjustment->status)->toBe(AdjustmentStatus::Confirmed)
        ->and($adjustment->adjustment_number)->not->toBeNull();

    $stockA = InventoryStock::query()->where('product_variant_id', $variantA->id)->where('warehouse_id', $warehouse->id)->firstOrFail();
    $stockB = InventoryStock::query()->where('product_variant_id', $variantB->id)->where('warehouse_id', $warehouse->id)->firstOrFail();

    expect((float) $stockA->on_hand_quantity)->toBe(13.0)
        ->and((float) $stockB->on_hand_quantity)->toBe(2.0);

    $movements = InventoryMovement::query()->where('source_type', 'adjustment')->where('source_id', $adjustment->id)->get();

    expect($movements)->toHaveCount(2)
        ->and($movements->firstWhere('product_variant_id', $variantA->id)?->quantity)->toEqual(3.0)
        ->and($movements->firstWhere('product_variant_id', $variantB->id)?->quantity)->toEqual(-3.0);

    $auditLog = AuditLog::query()->where('subject_type', InventoryAdjustment::class)->where('subject_id', $adjustment->id)->firstOrFail();

    expect($auditLog->description)->toBe('inventory.adjustment.confirmed')
        ->and($auditLog->causer_id)->toBe($actor->id)
        ->and($auditLog->causer->is($actor))->toBeTrue()
        ->and($auditLog->source_channel)->toBe('dashboard');

    $movementA = $movements->firstWhere('product_variant_id', $variantA->id);
    $item = $adjustment->items()->where('product_variant_id', $variantA->id)->firstOrFail();

    expect($item->adjustment->is($adjustment))->toBeTrue()
        ->and($movementA)->not->toBeNull();
});

it('refuses confirmation when the adjustment has no items', function (): void {
    $adjustment = InventoryAdjustment::factory()->create();

    expect(fn () => confirmService()->confirm($adjustment, User::factory()->create()))
        ->toThrow(DomainException::class);

    expect(AuditLog::query()->count())->toBe(0)
        ->and($adjustment->fresh()->status)->toBe(AdjustmentStatus::Draft);
});

it('rejects an adjustment package that belongs to another warehouse', function (): void {
    $warehouse = Warehouse::factory()->create();
    $foreignPackage = Package::factory()->create();
    $variant = ProductVariant::factory()->create();
    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create([
        'product_variant_id' => $variant->getKey(),
        'package_id' => $foreignPackage->getKey(),
        'new_quantity' => '1.000',
    ]);

    expect(fn (): mixed => confirmService()->confirm($adjustment, User::factory()->create()))
        ->toThrow(DomainException::class, __('admin.package.errors.warehouse_mismatch'));
});

it('establishes a balance for a variant with no existing stock row', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create(['product_variant_id' => $variant->id, 'new_quantity' => '7.000']);

    confirmService()->confirm($adjustment, User::factory()->create());

    $stock = InventoryStock::query()->where('product_variant_id', $variant->id)->where('warehouse_id', $warehouse->id)->firstOrFail();
    $movement = InventoryMovement::query()->where('product_variant_id', $variant->id)->firstOrFail();

    expect((float) $stock->on_hand_quantity)->toBe(7.0)
        ->and((float) $movement->quantity)->toBe(7.0);
});

it('writes a zero-quantity movement for an unchanged line without touching the balance', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'available_quantity' => '10.000']);
    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create(['product_variant_id' => $variant->id, 'new_quantity' => '10.000']);

    confirmService()->confirm($adjustment, User::factory()->create());

    $stock = InventoryStock::query()->where('product_variant_id', $variant->id)->firstOrFail();
    $movements = InventoryMovement::query()->where('product_variant_id', $variant->id)->get();

    expect((float) $stock->on_hand_quantity)->toBe(10.0)
        ->and($movements)->toHaveCount(1)
        ->and((float) $movements->first()->quantity)->toBe(0.0);
});

it('rolls back everything when the warehouse is inactive, leaving nothing changed', function (): void {
    $warehouse = Warehouse::factory()->create(['is_active' => false]);
    $variant = ProductVariant::factory()->create();
    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create(['product_variant_id' => $variant->id, 'new_quantity' => '5.000']);

    expect(fn () => confirmService()->confirm($adjustment, User::factory()->create()))
        ->toThrow(DomainException::class);

    expect(InventoryMovement::query()->count())->toBe(0)
        ->and(InventoryStock::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and($adjustment->fresh()->status)->toBe(AdjustmentStatus::Draft);
});

it('rolls back everything when the resulting on-hand would be negative', function (): void {
    // new_quantity is validated non-negative at the UI (FR-005), so the only way to reach this
    // domain guard is a line that bypasses that validation — exercised here directly against
    // the service, matching the contract's "defense in depth" framing (R8).
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['on_hand_quantity' => '5.000']);
    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create(['product_variant_id' => $variant->id, 'new_quantity' => '-1.000']);

    expect(fn () => confirmService()->confirm($adjustment, User::factory()->create()))
        ->toThrow(DomainException::class);

    expect(InventoryMovement::query()->count())->toBe(0)
        ->and(InventoryStock::query()->first()?->on_hand_quantity)->toEqual(5.0)
        ->and($adjustment->fresh()->status)->toBe(AdjustmentStatus::Draft);
});

it('refuses to confirm an already-confirmed adjustment', function (): void {
    $adjustment = InventoryAdjustment::factory()->confirmed()->create();
    $adjustment->items()->create([
        'product_variant_id' => ProductVariant::factory()->create()->id,
        'new_quantity' => '3.000',
    ]);

    expect(fn () => confirmService()->confirm($adjustment, User::factory()->create()))
        ->toThrow(DomainException::class);

    expect(InventoryMovement::query()->count())->toBe(0);
});

it('assigns exactly one movement per item, never more and never fewer', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variants = ProductVariant::factory()->count(3)->create();
    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();

    foreach ($variants as $variant) {
        $adjustment->items()->create(['product_variant_id' => $variant->id, 'new_quantity' => '1.000']);
    }

    confirmService()->confirm($adjustment, User::factory()->create());

    expect(InventoryMovement::query()->where('source_type', 'adjustment')->where('source_id', $adjustment->id)->count())->toBe(3);
});

it('adjusts a lot-tracked count at the lot grain and keeps aggregate and lot quantities equal', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $stock = InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'available_quantity' => '10.000000',
    ]);
    $lot = \App\Models\InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);
    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create([
        'product_variant_id' => $variant->getKey(),
        'inventory_lot_id' => $lot->getKey(),
        'new_quantity' => '7.000000',
    ]);

    confirmService()->confirm($adjustment, User::factory()->create());

    expect($stock->refresh()->on_hand_quantity)->toBe('7.000000')
        ->and($lot->refresh()->on_hand_quantity)->toBe('7.000000')
        ->and(InventoryMovement::query()->where('source_type', 'adjustment')->sole()->inventory_lot_id)->toBe($lot->getKey());
});

it('rejects a lot-tracked adjustment without an explicit lot allocation', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'available_quantity' => '5.000000',
    ]);
    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create([
        'product_variant_id' => $variant->getKey(),
        'new_quantity' => '4.000000',
    ]);

    expect(fn () => confirmService()->confirm($adjustment, User::factory()->create()))
        ->toThrow(DomainException::class, __('admin.inventory.lot.errors.required'));

    expect($adjustment->fresh()->status)->toBe(AdjustmentStatus::Draft)
        ->and(InventoryMovement::query()->where('source_type', 'adjustment')->count())->toBe(0);
});

it('rejects a lot count that would strand an active lot reservation above counted on-hand', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $stock = InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '3.000000',
        'available_quantity' => '2.000000',
    ]);
    $lot = \App\Models\InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '3.000000',
        'expires_at' => null,
    ]);
    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create([
        'product_variant_id' => $variant->getKey(),
        'inventory_lot_id' => $lot->getKey(),
        'new_quantity' => '2.000000',
    ]);

    expect(fn () => confirmService()->confirm($adjustment, User::factory()->create()))
        ->toThrow(DomainException::class);

    expect($stock->refresh()->on_hand_quantity)->toBe('5.000000')
        ->and($lot->refresh()->on_hand_quantity)->toBe('5.000000');
});

it('adjusts one serialized unit without interpreting the line as the whole warehouse count', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $stock = InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '2.000000',
        'reserved_quantity' => '0.000000',
        'available_quantity' => '2.000000',
    ]);
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
        'custody_type' => \App\Enums\SerializedCustodyType::Warehouse,
    ]);
    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create([
        'product_variant_id' => $variant->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'new_quantity' => '0.000000',
    ]);

    confirmService()->confirm($adjustment, User::factory()->create());

    expect($stock->refresh()->on_hand_quantity)->toBe('1.000000')
        ->and($unit->refresh()->status)->toBe(SerializedInventoryUnitStatus::AdjustedOut)
        ->and($unit->warehouse_id)->toBeNull();
});

it('counts only saleable quantity when quarantine or damaged stock also exists', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $stock = InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '3.000000',
        'available_quantity' => '5.000000',
    ]);

    foreach ([
        StockCondition::Saleable->value => ['5.000000', '0.000000'],
        StockCondition::Quarantine->value => ['2.000000', '0.000000'],
        StockCondition::Damaged->value => ['3.000000', '0.000000'],
    ] as $condition => [$onHand, $reserved]) {
        InventoryConditionBalance::query()->forceCreate([
            'product_variant_id' => $variant->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $condition,
            'on_hand_base_quantity' => $onHand,
            'reserved_base_quantity' => $reserved,
        ]);
    }

    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $item = $adjustment->items()->create([
        'product_variant_id' => $variant->getKey(),
        'new_quantity' => '4.000000',
    ]);

    confirmService()->confirm($adjustment, User::factory()->create());

    expect($item->refresh()->old_quantity)->toBe('5.000000')
        ->and($item->difference)->toBe('-1.000000')
        ->and($stock->refresh()->on_hand_quantity)->toBe('9.000000')
        ->and($stock->damaged_quantity)->toBe('3.000000')
        ->and($stock->available_quantity)->toBe('4.000000');
});

it('counts only the selected lot saleable condition quantity', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $stock = InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '10.000000',
    ]);
    $lot = \App\Models\InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);

    InventoryLotBalance::query()->where('inventory_lot_id', $lot->getKey())->delete();

    foreach ([
        StockCondition::Saleable->value => ['4.000000', '0.000000'],
        StockCondition::Quarantine->value => ['2.000000', '0.000000'],
        StockCondition::Damaged->value => ['4.000000', '0.000000'],
    ] as $condition => [$onHand, $reserved]) {
        InventoryLotBalance::query()->forceCreate([
            'inventory_lot_id' => $lot->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $condition,
            'on_hand_base_quantity' => $onHand,
            'reserved_base_quantity' => $reserved,
        ]);
    }

    // The aggregate stock row must reflect the same condition split for canonical reconciliation.
    InventoryConditionBalance::query()->where('product_variant_id', $variant->getKey())->delete();
    foreach ([
        StockCondition::Saleable->value => ['4.000000', '0.000000'],
        StockCondition::Quarantine->value => ['2.000000', '0.000000'],
        StockCondition::Damaged->value => ['4.000000', '0.000000'],
    ] as $condition => [$onHand, $reserved]) {
        InventoryConditionBalance::query()->forceCreate([
            'product_variant_id' => $variant->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $condition,
            'on_hand_base_quantity' => $onHand,
            'reserved_base_quantity' => $reserved,
        ]);
    }
    $stock->forceFill(['damaged_quantity' => '4.000000', 'available_quantity' => '4.000000'])->save();

    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $item = $adjustment->items()->create([
        'product_variant_id' => $variant->getKey(),
        'inventory_lot_id' => $lot->getKey(),
        'new_quantity' => '3.000000',
    ]);

    confirmService()->confirm($adjustment, User::factory()->create());

    expect($item->refresh()->old_quantity)->toBe('4.000000')
        ->and($item->difference)->toBe('-1.000000')
        ->and($lot->refresh()->totalPhysicalQuantity())->toBe(9.0)
        ->and($lot->conditionOnHandQuantity(StockCondition::Saleable, (int) $warehouse->getKey()))->toBe(3.0)
        ->and($lot->conditionOnHandQuantity(StockCondition::Quarantine, (int) $warehouse->getKey()))->toBe(2.0)
        ->and($lot->conditionOnHandQuantity(StockCondition::Damaged, (int) $warehouse->getKey()))->toBe(4.0);
});

it('rejects adjustments for inactive variants', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['is_active' => false]);
    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create([
        'product_variant_id' => $variant->getKey(),
        'new_quantity' => 1,
    ]);

    expect(fn () => confirmService()->confirm($adjustment, User::factory()->create()))
        ->toThrow(DomainException::class, __('admin.inventory.adjustment.errors.inactive_variant'));
});

it('rejects a serialized adjustment for a different variant', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => ProductVariant::factory(),
        'warehouse_id' => $warehouse->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
    ]);
    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => 1,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 1,
    ]);
    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create([
        'product_variant_id' => $variant->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'new_quantity' => 0,
    ]);

    expect(fn () => confirmService()->confirm($adjustment, User::factory()->create()))
        ->toThrow(DomainException::class, __('admin.inventory.adjustment.errors.invalid_serial'));
});

it('rejects serialized adjustment-out devices that are unavailable or elsewhere', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => Warehouse::factory(),
        'status' => SerializedInventoryUnitStatus::Pending,
    ]);
    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => 1,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 1,
    ]);
    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create([
        'product_variant_id' => $variant->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'new_quantity' => 0,
    ]);

    expect(fn () => confirmService()->confirm($adjustment, User::factory()->create()))
        ->toThrow(DomainException::class, __('admin.inventory.adjustment.errors.invalid_serial'));
});

it('rejects serialized adjustment-in devices that were not adjusted out', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => null,
        'status' => SerializedInventoryUnitStatus::Available,
    ]);
    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create([
        'product_variant_id' => $variant->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'new_quantity' => 1,
    ]);

    expect(fn () => confirmService()->confirm($adjustment, User::factory()->create()))
        ->toThrow(DomainException::class, __('admin.inventory.adjustment.errors.invalid_serial'));
});
