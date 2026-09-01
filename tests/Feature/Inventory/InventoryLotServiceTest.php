<?php

declare(strict_types=1);

use App\Enums\StockCondition;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryLotService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('refuses an expiry-tracked receipt line without expiry', function (): void {
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    $warehouse = Warehouse::factory()->create();
    $operation = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
    ]);
    $line = $operation->lines()->make([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '5.000000',
        'unit_id' => $variant->unit_id,
        'lot_number' => 'EXP-001',
        'expires_at' => null,
    ]);

    expect(fn () => app(InventoryLotService::class)->receive(
        $line,
        $variant,
        (int) $warehouse->getKey(),
    ))->toThrow(DomainException::class, __('admin.inventory.product_type.errors.expiry_required'));
});

it('reuses one normalized lot identity across receipts into different warehouses', function (): void {
    $variant = ProductVariant::factory()->grain()->create();
    $firstWarehouse = Warehouse::factory()->create();
    $secondWarehouse = Warehouse::factory()->create();

    $firstOperation = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $firstWarehouse->getKey(),
    ]);
    $firstLine = $firstOperation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '5.000000',
        'unit_id' => $variant->unit_id,
        'lot_number' => ' lot   alpha ',
        'expires_at' => null,
    ]);

    $secondOperation = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $secondWarehouse->getKey(),
    ]);
    $secondLine = $secondOperation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '3.000000',
        'unit_id' => $variant->unit_id,
        'lot_number' => 'LOT ALPHA',
        'expires_at' => null,
    ]);

    $service = app(InventoryLotService::class);
    $first = $service->receive($firstLine, $variant, (int) $firstWarehouse->getKey(), '5.000000');
    $second = $service->receive($secondLine, $variant, (int) $secondWarehouse->getKey(), '3.000000');

    expect($first)->not->toBeNull()
        ->and($second?->getKey())->toBe($first?->getKey())
        ->and($first?->normalized_lot_number)->toBe('LOT ALPHA')
        ->and($first?->warehouse_id)->toBeNull()
        ->and(InventoryLot::query()->canonical()->where('product_variant_id', $variant->getKey())->count())->toBe(1);
});

it('rejects the same normalized lot number with a different immutable expiry', function (): void {
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    $warehouse = Warehouse::factory()->create();
    $service = app(InventoryLotService::class);

    $firstOperation = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
    ]);
    $firstLine = $firstOperation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000000',
        'unit_id' => $variant->unit_id,
        'lot_number' => 'EXP-SAME',
        'expires_at' => today()->addDays(30),
    ]);
    $service->receive($firstLine, $variant, (int) $warehouse->getKey(), '1.000000');

    $secondOperation = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
    ]);
    $secondLine = $secondOperation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000000',
        'unit_id' => $variant->unit_id,
        'lot_number' => ' exp-same ',
        'expires_at' => today()->addDays(60),
    ]);

    expect(fn () => $service->receive(
        $secondLine,
        $variant,
        (int) $warehouse->getKey(),
        '1.000000',
    ))->toThrow(DomainException::class, 'different immutable expiry date');
});

it('uses only the requested warehouse saleable lot balance for outbound eligibility', function (): void {
    $variant = ProductVariant::factory()->grain()->create();
    $warehouseA = Warehouse::factory()->create();
    $warehouseB = Warehouse::factory()->create();
    $lot = InventoryLot::factory()->canonical()->for($variant, 'productVariant')->create([
        'lot_number' => 'WAREHOUSE-SPLIT',
        'expires_at' => today()->addDays(20),
    ]);

    foreach ([
        [$warehouseA, '2.000000', '0.000000'],
        [$warehouseB, '10.000000', '0.000000'],
    ] as [$warehouse, $onHand, $reserved]) {
        foreach ([
            StockCondition::Saleable->value => [$onHand, $reserved],
            StockCondition::Quarantine->value => ['0.000000', '0.000000'],
            StockCondition::Damaged->value => ['0.000000', '0.000000'],
        ] as $condition => [$conditionOnHand, $conditionReserved]) {
            InventoryLotBalance::query()->forceCreate([
                'inventory_lot_id' => $lot->getKey(),
                'warehouse_id' => $warehouse->getKey(),
                'stock_condition' => $condition,
                'on_hand_base_quantity' => $conditionOnHand,
                'reserved_base_quantity' => $conditionReserved,
            ]);
        }
    }

    $line = InventoryOperationLine::factory()->make([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '3.000000',
        'base_quantity' => '3.000000',
        'inventory_lot_id' => $lot->getKey(),
    ]);

    expect(fn () => app(InventoryLotService::class)->consume(
        $line,
        $variant,
        (int) $warehouseA->getKey(),
        null,
    ))->toThrow(DomainException::class);

    expect(app(InventoryLotService::class)->consume(
        $line,
        $variant,
        (int) $warehouseB->getKey(),
        null,
    )?->getKey())->toBe($lot->getKey());
});

it('FEFO exposes only saleable availability in the selected warehouse', function (): void {
    $variant = ProductVariant::factory()->grain()->create();
    $warehouse = Warehouse::factory()->create();
    $otherWarehouse = Warehouse::factory()->create();

    $earliest = InventoryLot::factory()->canonical()->for($variant, 'productVariant')->create([
        'lot_number' => 'EARLY',
        'expires_at' => today()->addDays(5),
    ]);
    $later = InventoryLot::factory()->canonical()->for($variant, 'productVariant')->create([
        'lot_number' => 'LATE',
        'expires_at' => today()->addDays(20),
    ]);

    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $earliest->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '3.000000',
        'reserved_base_quantity' => '0.000000',
    ]);
    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $later->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '2.000000',
        'reserved_base_quantity' => '0.000000',
    ]);
    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $earliest->getKey(),
        'warehouse_id' => $otherWarehouse->getKey(),
        'stock_condition' => StockCondition::Damaged,
        'on_hand_base_quantity' => '100.000000',
        'reserved_base_quantity' => '0.000000',
    ]);

    $lots = app(InventoryLotService::class)->availableLots(
        (int) $variant->getKey(),
        (int) $warehouse->getKey(),
    );

    expect($lots->pluck('id')->all())->toBe([$earliest->getKey(), $later->getKey()]);
});
