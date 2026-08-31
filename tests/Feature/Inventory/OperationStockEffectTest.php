<?php

declare(strict_types=1);

use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\InventoryStock;
use App\Models\SerializedInventoryUnit;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\ProductVariantUomService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function stockEffectService(): InventoryOperationService
{
    return app(InventoryOperationService::class);
}

// The custody rule (R-001, FR-003): a warehouse's balance changes exactly when that warehouse's
// custody of the goods changes — never at Draft, Waiting, or Ready.

it('gains destination on-hand only at Done for a receipt, not at Ready', function (): void {
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
    $operation->lines()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '5.000', 'unit_id' => $variant->unit_id]);
    $actor = User::factory()->create();

    stockEffectService()->markReady($operation);

    expect(InventoryStock::query()->where('product_variant_id', $variant->getKey())->where('warehouse_id', $destination->getKey())->doesntExist())->toBeTrue();

    stockEffectService()->complete($operation->refresh(), $actor);

    $stock = InventoryStock::query()->where('product_variant_id', $variant->getKey())->where('warehouse_id', $destination->getKey())->firstOrFail();

    expect((float) $stock->on_hand_quantity)->toBe(5.0)
        ->and($operation->refresh()->isDone())->toBeTrue()
        ->and(InventoryMovement::query()->where('source_type', 'inventory_operation')->where('source_id', $operation->getKey())->count())->toBe(1);
});

it('loses source on-hand only at Done for a delivery, not at Ready', function (): void {
    $source = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($source)->create(['on_hand_quantity' => '10.000', 'available_quantity' => '10.000']);
    // The factory's default variant is a Grain, which is batch-tracked, so the line below has
    // to name the batch it draws from.
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create(['on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'expires_at' => null]);
    $operation = InventoryOperation::factory()->delivery()->create(['source_warehouse_id' => $source->getKey()]);
    $operation->lines()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '4.000', 'unit_id' => $variant->unit_id, 'inventory_lot_id' => $lot->getKey()]);
    $actor = User::factory()->create();

    stockEffectService()->markReady($operation->refresh());

    $reservedStock = InventoryStock::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $source->getKey())
        ->firstOrFail();

    expect((float) $reservedStock->on_hand_quantity)->toBe(10.0)
        ->and((float) $reservedStock->reserved_quantity)->toBe(4.0);

    stockEffectService()->complete($operation->refresh(), $actor);

    $stock = InventoryStock::query()->where('product_variant_id', $variant->getKey())->where('warehouse_id', $source->getKey())->firstOrFail();

    $movement = InventoryMovement::query()
        ->where('source_type', 'inventory_operation')
        ->where('source_id', $operation->getKey())
        ->sole();

    expect((float) $stock->on_hand_quantity)->toBe(6.0)
        ->and((float) $stock->reserved_quantity)->toBe(0.0)
        ->and($movement->transaction_quantity)->toBe('4.000000')
        ->and($movement->conversion_factor_snapshot)->toBe('1.000000')
        ->and($movement->base_quantity_delta)->toBe('-4.000000');
});

it('rolls back lot and aggregate delivery mutations when the canonical movement cannot be persisted', function (): void {
    $source = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $stock = InventoryStock::factory()->for($variant)->for($source)->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '0.000',
        'available_quantity' => '10.000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '0.000',
        'expires_at' => null,
    ]);
    $operation = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $source->getKey(),
    ]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '4.000',
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);

    stockEffectService()->markReady($operation->refresh());

    $actor = User::factory()->create();
    $actorId = $actor->getKey();

    DB::table('users')->where('id', $actorId)->delete();

    expect(fn (): InventoryOperation => stockEffectService()->complete($operation->refresh(), $actor))
        ->toThrow(QueryException::class);

    expect($operation->refresh()->stage->value)->toBe('ready')
        ->and($stock->refresh()->on_hand_quantity)->toBe('10.000000')
        ->and($stock->reserved_quantity)->toBe('4.000000')
        ->and($lot->refresh()->on_hand_quantity)->toBe('10.000000')
        ->and($lot->reserved_quantity)->toBe('4.000000')
        ->and(InventoryMovement::query()
            ->where('source_type', 'inventory_operation')
            ->where('source_id', $operation->getKey())
            ->count())->toBe(0);
});

it('reserves and delivers the normalized base quantity for a non-base transaction UOM', function (): void {
    $piece = Unit::factory()->create([
        'code' => 'PIECE-DELIVERY',
        'name' => 'Piece',
        'symbol' => 'pc',
        'family' => 'count',
        'precision' => 0,
        'allows_decimal' => false,
    ]);
    $box = Unit::factory()->create([
        'code' => 'BOX-DELIVERY',
        'name' => 'Box',
        'symbol' => 'box',
        'family' => 'count',
        'precision' => 0,
        'allows_decimal' => false,
    ]);
    $variant = ProductVariant::factory()->create();

    app(ProductVariantUomService::class)->sync($variant, [
        [
            'unit_id' => (int) $piece->getKey(),
            'is_base' => true,
            'is_purchase' => true,
            'is_sale' => true,
            'is_display' => true,
            'factor_to_base' => '1',
            'rounding_increment' => '1',
            'permits_cross_family_conversion' => false,
            'is_active' => true,
        ],
        [
            'unit_id' => (int) $box->getKey(),
            'is_base' => false,
            'is_purchase' => true,
            'is_sale' => true,
            'is_display' => false,
            'factor_to_base' => '100',
            'rounding_increment' => '1',
            'permits_cross_family_conversion' => false,
            'is_active' => true,
        ],
    ]);

    $source = Warehouse::factory()->create();
    InventoryStock::factory()->for($variant)->for($source)->create([
        'on_hand_quantity' => '500.000',
        'available_quantity' => '500.000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create([
        'on_hand_quantity' => '500.000',
        'reserved_quantity' => '0.000',
        'expires_at' => null,
    ]);
    $operation = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $source->getKey(),
    ]);
    $line = $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '2.000',
        'unit_id' => $box->getKey(),
        'inventory_lot_id' => $lot->getKey(),
    ]);
    $actor = User::factory()->create();

    stockEffectService()->markReady($operation->refresh());

    $stock = InventoryStock::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $source->getKey())
        ->sole();

    expect($line->refresh()->transaction_quantity)->toBe('2.000000')
        ->and($line->conversion_factor_snapshot)->toBe('100.000000')
        ->and($line->base_quantity)->toBe('200.000000')
        ->and($stock->reserved_quantity)->toBe('200.000000')
        ->and($stock->available_quantity)->toBe('300.000000');

    stockEffectService()->complete($operation->refresh(), $actor);

    $movement = InventoryMovement::query()
        ->where('source_type', 'inventory_operation')
        ->where('source_id', $operation->getKey())
        ->sole();

    expect($stock->refresh()->on_hand_quantity)->toBe('300.000000')
        ->and($stock->reserved_quantity)->toBe('0.000000')
        ->and($movement->transaction_quantity)->toBe('2.000000')
        ->and($movement->transaction_unit_id)->toBe($box->getKey())
        ->and($movement->conversion_factor_snapshot)->toBe('100.000000')
        ->and($movement->base_quantity_delta)->toBe('-200.000000');
});

it('posts lot and serialized custody through the canonical delivery boundary', function (): void {
    $source = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $stock = InventoryStock::factory()->for($variant)->for($source)->create([
        'on_hand_quantity' => '1.000000',
        'reserved_quantity' => '0.000000',
        'available_quantity' => '1.000000',
    ]);
    $unit = \App\Models\SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $source->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
        'custody_type' => SerializedCustodyType::Warehouse,
    ]);
    $operation = InventoryOperation::factory()->delivery()->create(['source_warehouse_id' => $source->getKey()]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1',
        'unit_id' => $variant->unit_id,
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]);
    $actor = User::factory()->create();

    stockEffectService()->markReady($operation, $actor);
    stockEffectService()->complete($operation->refresh(), $actor);

    expect($stock->refresh()->on_hand_quantity)->toBe('0.000000')
        ->and($unit->refresh()->status)->toBe(SerializedInventoryUnitStatus::Delivered)
        ->and($unit->warehouse_id)->toBeNull()
        ->and($unit->custody_type)->toBe(SerializedCustodyType::Customer)
        ->and($unit->custody_reference_type)->toBe('inventory_operation')
        ->and($unit->custody_reference_id)->toBe($operation->getKey());
});

it('rejects a non-saleable serialized unit before an outbound operation becomes ready', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '1.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '0.000000',
    ]);
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
        'custody_type' => SerializedCustodyType::Warehouse,
        'stock_condition' => StockCondition::Quarantine,
    ]);
    $operation = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
    ]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1',
        'unit_id' => $variant->unit_id,
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]);

    expect(fn () => stockEffectService()->markReady($operation, User::factory()->create()))
        ->toThrow(DomainException::class, 'The selected serialized unit is not saleable stock in the source warehouse.');

    expect($operation->refresh()->stage->value)->toBe('draft');
});

it('loses source on-hand at InTransit and gains destination on-hand at Done for an internal transfer', function (): void {
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($source)->create(['on_hand_quantity' => '10.000', 'available_quantity' => '10.000']);
    // The factory's default variant is a Grain, which is batch-tracked, so the line below has
    // to name the batch it draws from.
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create(['on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'expires_at' => null]);
    $operation = InventoryOperation::factory()->internalTransfer()->create([
        'source_warehouse_id' => $source->getKey(),
        'destination_warehouse_id' => $destination->getKey(),
    ]);
    $operation->lines()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '3.000', 'unit_id' => $variant->unit_id, 'inventory_lot_id' => $lot->getKey()]);
    $actor = User::factory()->create();

    stockEffectService()->markReady($operation->refresh());

    expect((float) InventoryStock::query()->where('product_variant_id', $variant->getKey())->where('warehouse_id', $source->getKey())->value('on_hand_quantity'))->toBe(10.0);

    stockEffectService()->dispatch($operation->refresh(), $actor);

    $sourceStock = InventoryStock::query()->where('product_variant_id', $variant->getKey())->where('warehouse_id', $source->getKey())->firstOrFail();

    expect((float) $sourceStock->on_hand_quantity)->toBe(7.0)
        ->and($operation->refresh()->isInTransit())->toBeTrue()
        ->and(InventoryStock::query()->where('warehouse_id', $destination->getKey())->doesntExist())->toBeTrue()
        ->and(InventoryMovement::query()->where('source_type', 'inventory_operation')->where('source_id', $operation->getKey())->count())->toBe(1);

    stockEffectService()->complete($operation->refresh(), $actor);

    $destinationStock = InventoryStock::query()->where('product_variant_id', $variant->getKey())->where('warehouse_id', $destination->getKey())->firstOrFail();

    expect((float) $destinationStock->on_hand_quantity)->toBe(3.0)
        ->and($operation->refresh()->isDone())->toBeTrue()
        ->and(InventoryMovement::query()->where('source_type', 'inventory_operation')->where('source_id', $operation->getKey())->count())->toBe(2);
});

it('writes exactly one movement per balance change across multiple lines', function (): void {
    $destination = Warehouse::factory()->create();
    $variantA = ProductVariant::factory()->create();
    $variantB = ProductVariant::factory()->create();
    $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
    $operation->lines()->create(['product_variant_id' => $variantA->getKey(), 'quantity' => '2.000', 'unit_id' => $variantA->unit_id]);
    $operation->lines()->create(['product_variant_id' => $variantB->getKey(), 'quantity' => '6.000', 'unit_id' => $variantB->unit_id]);

    stockEffectService()->markReady($operation);
    stockEffectService()->complete($operation->refresh(), User::factory()->create());

    expect(InventoryMovement::query()->where('source_type', 'inventory_operation')->where('source_id', $operation->getKey())->count())->toBe(2);
});
