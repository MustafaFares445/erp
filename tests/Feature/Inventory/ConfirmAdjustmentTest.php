<?php

declare(strict_types=1);

use App\Enums\AdjustmentStatus;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\AuditLog;
use App\Models\InventoryAdjustment;
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

    $auditLog = AuditLog::query()->where('entity_type', InventoryAdjustment::class)->where('entity_id', $adjustment->id)->firstOrFail();

    expect($auditLog->action)->toBe('inventory.adjustment.confirmed')
        ->and($auditLog->actor_user_id)->toBe($actor->id)
        ->and($auditLog->actor->is($actor))->toBeTrue()
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
