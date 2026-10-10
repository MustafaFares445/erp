<?php

declare(strict_types=1);

use App\Data\Inventory\CountScopeData;
use App\Data\Inventory\DamageDraftData;
use App\Enums\AdjustmentStatus;
use App\Enums\ConditionChangeReason;
use App\Enums\CountScope;
use App\Enums\InventoryConditionChangeStatus;
use App\Enums\InventoryCorrectionStatus;
use App\Enums\InventoryPermission;
use App\Enums\InventoryReturnDisposition;
use App\Enums\InventoryReturnStatus;
use App\Enums\MovementType;
use App\Enums\StockCondition;
use App\Models\CreditNote;
use App\Models\InventoryAdjustment;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\InventoryConditionChangeService;
use App\Services\Inventory\InventoryCorrectionService;
use App\Services\Inventory\InventoryCountService;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\InventoryReturnService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('scopes a physical count to selected variants and keeps nonselected variants out', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $unselected = ProductVariant::factory()->grain()->create();

    foreach ([$variant, $unselected] as $item) {
        InventoryStock::factory()->for($item, 'productVariant')->for($warehouse)->create([
            'on_hand_quantity' => '4.000000', 'reserved_quantity' => '0.000000',
            'damaged_quantity' => '0.000000', 'available_quantity' => '4.000000',
        ]);
        $lot = InventoryLot::factory()->for($item, 'productVariant')->for($warehouse)->create([
            'on_hand_quantity' => '4.000000', 'reserved_quantity' => '0.000000',
        ]);
        InventoryConditionBalance::query()->firstOrCreate([
            'product_variant_id' => $item->getKey(), 'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => StockCondition::Saleable,
        ], ['on_hand_base_quantity' => '4.000000', 'reserved_base_quantity' => '0.000000']);
        InventoryLotBalance::query()->firstOrCreate([
            'inventory_lot_id' => $lot->getKey(), 'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => StockCondition::Saleable->value,
        ], ['on_hand_base_quantity' => '4.000000', 'reserved_base_quantity' => '0.000000']);
    }

    $count = app(InventoryCountService::class)->open(new CountScopeData(
        warehouseId: (int) $warehouse->getKey(), scopeType: CountScope::VariantSet,
        productCategoryId: null, inventoryLotId: null, productVariantIds: [(int) $variant->getKey()],
        conditions: [StockCondition::Saleable->value], materialityThresholdMinor: null,
    ), User::factory()->create());

    expect($count->lines()->count())->toBeGreaterThan(0)
        ->and($count->lines()->where('product_variant_id', $unselected->getKey())->count())->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0);
});

it('records adjustment posting as a signed movement, preserves the Draft until posting, and blocks replay', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $stock = InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000000', 'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000', 'available_quantity' => '10.000000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000000', 'reserved_quantity' => '0.000000',
    ]);
    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create([
        'product_variant_id' => $variant->getKey(), 'stock_condition' => StockCondition::Saleable,
        'inventory_lot_id' => $lot->getKey(), 'new_quantity' => '8.000000',
    ]);

    expect($adjustment->status)->toBe(AdjustmentStatus::Draft)
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and($stock->refresh()->on_hand_quantity)->toBe('10.000000');

    $service = app(InventoryAdjustmentService::class);
    $service->confirm($adjustment, User::factory()->create());

    $movement = InventoryMovement::query()
        ->where('source_type', 'adjustment')
        ->where('source_id', $adjustment->getKey())->sole();

    expect($adjustment->refresh()->status)->toBe(AdjustmentStatus::Confirmed)
        ->and($movement->quantity)->toBe('-2.000000')
        ->and($stock->refresh()->on_hand_quantity)->toBe('8.000000');
    expect(fn () => $service->confirm($adjustment->refresh(), User::factory()->create()))->toThrow(DomainException::class);
    expect(InventoryMovement::query()->where('source_type', 'adjustment')
        ->where('source_id', $adjustment->getKey())->count())->toBe(1);
});

it('uses compensating receipt corrections and retains the posted source movement unchanged', function (): void {
    $warehouse = Warehouse::factory()->create();
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $actor = User::factory()->create();
    $receipt = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
        'supplier_id' => $supplier->getKey(),
    ]);
    $sourceLine = $receipt->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '5.000000', 'unit_id' => $variant->unit_id, 'lot_number' => 'AGENT1-PHASE4',
    ]);
    $operations = app(InventoryOperationService::class);
    $operations->markReady($receipt, $actor);
    $operations->complete($receipt->refresh(), $actor);

    $sourceMovement = InventoryMovement::query()
        ->where('source_type', 'inventory_operation')
        ->where('source_id', $receipt->getKey())
        ->where('movement_type', MovementType::Receipt->value)->sole();
    $service = app(InventoryCorrectionService::class);
    $correction = $service->createReceiptCorrection($actor, $receipt->refresh(), 'Physically received two fewer units');
    $service->addReceiptLine($correction, $sourceLine->refresh(), '2.000000');
    $posted = $service->post($correction, $actor);
    $reversal = InventoryMovement::query()
        ->where('source_type', 'inventory_correction')
        ->where('source_id', $correction->getKey())->sole();

    expect($posted->status)->toBe(InventoryCorrectionStatus::Posted)
        ->and($reversal->quantity)->toBe('-2.000000')
        ->and($reversal->reversal_of_movement_id)->toBe($sourceMovement->getKey())
        ->and($sourceMovement->refresh()->quantity)->toBe('5.000000')
        ->and(InventoryStock::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $warehouse->getKey())->sole()->on_hand_quantity)->toBe('3.000000');
});

it('posts a customer return without inventing a credit note or refund', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $actor = User::factory()->create();
    $stock = InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '3.000000', 'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000', 'available_quantity' => '3.000000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '3.000000', 'reserved_quantity' => '0.000000',
    ]);
    $delivery = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
    ]);
    $sourceLine = $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000000',
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);
    $operations = app(InventoryOperationService::class);
    $operations->markReady($delivery, $actor);
    $operations->complete($delivery->refresh(), $actor);

    $service = app(InventoryReturnService::class);
    $return = $service->createCustomerReturn($actor, $delivery->refresh(), $warehouse);
    $line = $service->addCustomerLine($return, $sourceLine->refresh(), '1.000000', (int) $lot->getKey());
    $service->inspectLine($line, InventoryReturnDisposition::Saleable, $actor);
    $service->markReady($return, $actor);

    $posted = $service->post($return->refresh(), $actor);

    expect($posted->status)->toBe(InventoryReturnStatus::Posted)
        ->and($posted->credit_note_required)->toBeTrue()
        ->and($stock->refresh()->on_hand_quantity)->toBe('3.000000')
        ->and(InventoryMovement::query()->where('source_type', 'inventory_return')
            ->where('source_id', $return->getKey())->count())->toBe(1)
        ->and(CreditNote::query()->count())->toBe(0)
        ->and(Refund::query()->count())->toBe(0);
});

it('posts damage to damaged balance and lowers available stock without erasing on-hand history', function (): void {
    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => '10.000000', 'reserved_quantity' => '2.000000',
        'damaged_quantity' => '0.000000', 'available_quantity' => '8.000000',
    ]);
    $lot = InventoryLot::factory()->for($stock->productVariant, 'productVariant')
        ->for($stock->warehouse)->create([
            'on_hand_quantity' => '10.000000', 'reserved_quantity' => '2.000000',
        ]);
    (new InventoryPermissionSeeder)->run();
    $actor = User::factory()->create();
    $actor->givePermissionTo([
        InventoryPermission::ConditionChangeView->value,
        InventoryPermission::ConditionChangeCreate->value,
        InventoryPermission::ConditionChangePost->value,
        InventoryPermission::ConditionChangeCancel->value,
        InventoryPermission::StockView->value,
    ]);
    $service = app(InventoryConditionChangeService::class);
    $damage = $service->draftDamage(new DamageDraftData(
        productVariantId: (int) $stock->product_variant_id,
        warehouseId: (int) $stock->warehouse_id,
        inventoryLotId: (int) $lot->getKey(),
        serializedInventoryUnitId: null, baseQuantity: '3.000000',
        reasonCategory: ConditionChangeReason::DamagedInTransit,
        reason: 'Three units damaged in transit',
    ), $actor);
    expect($damage->status)->toBe(InventoryConditionChangeStatus::Draft);
    $posted = $service->post($damage, $actor);
    expect($posted->status)->toBe(InventoryConditionChangeStatus::Posted)
        ->and($stock->refresh()->on_hand_quantity)->toBe('10.000000')
        ->and($stock->damaged_quantity)->toBe('3.000000')
        ->and($stock->available_quantity)->toBe('5.000000');
});
