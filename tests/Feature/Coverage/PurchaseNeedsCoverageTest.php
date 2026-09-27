<?php

declare(strict_types=1);

use App\Filament\Pages\PurchaseNeeds;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SupplierProductReference;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('projects open Sales procurement demand with supplier and PO context', function (): void {
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->create();
    $line = OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 5,
        'unit_id' => $variant->unit_id,
    ]);
    $warehouse = Warehouse::factory()->create();
    $purchaseOrder = PurchaseOrder::factory()->create();

    $order->procurementRequirements()->create([
        'order_line_id' => $line->getKey(),
        'product_variant_id' => $variant->getKey(),
        'destination_warehouse_id' => $warehouse->getKey(),
        'purchase_order_id' => $purchaseOrder->getKey(),
        'required_base_quantity' => '5.000000',
        'fulfilled_base_quantity' => '2.000000',
        'status' => 'purchasing',
    ]);

    SupplierProductReference::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'is_active' => true,
    ]);

    $row = collect(app(PurchaseNeeds::class)->needs())
        ->firstWhere('source', 'Sales Order');

    expect($row)->not->toBeNull()
        ->and($row['source_reference'])->toBe($order->order_number)
        ->and($row['sku'])->toBe($variant->sku)
        ->and($row['warehouse'])->toBe($warehouse->name)
        ->and($row['remaining'])->toBe('3.000000')
        ->and($row['linked_po'])->toBe($purchaseOrder->purchase_order_number)
        ->and($row['supplier_count'])->toBe(1);
});

it('projects only replenishment demand that still requires external purchasing', function (): void {
    $target = Warehouse::factory()->create();
    $source = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    $targetStock = InventoryStock::factory()->create([
        'warehouse_id' => $target->getKey(),
        'product_variant_id' => $variant->getKey(),
        'on_hand_quantity' => 10,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 10,
    ]);
    $sourceStock = InventoryStock::factory()->create([
        'warehouse_id' => $source->getKey(),
        'product_variant_id' => $variant->getKey(),
        'on_hand_quantity' => 60,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 60,
    ]);

    WarehouseReplenishmentPolicy::query()->create([
        'warehouse_id' => $target->getKey(),
        'product_variant_id' => $variant->getKey(),
        'min_quantity' => 20,
        'max_quantity' => 60,
        'is_active' => true,
    ]);
    WarehouseReplenishmentPolicy::query()->create([
        'warehouse_id' => $source->getKey(),
        'product_variant_id' => $variant->getKey(),
        'min_quantity' => 20,
        'max_quantity' => 60,
        'is_active' => true,
    ]);

    SupplierProductReference::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'is_active' => true,
    ]);

    $row = collect(app(PurchaseNeeds::class)->needs())
        ->firstWhere('source', 'Inventory Replenishment');

    expect($row)->not->toBeNull()
        ->and($row['sku'])->toBe($variant->sku)
        ->and($row['warehouse'])->toBe($target->name)
        ->and($row['remaining'])->toBe('50.000000')
        ->and($row['supplier_count'])->toBe(1);

    $sourceStock->forceFill([
        'on_hand_quantity' => 120,
        'available_quantity' => 120,
    ])->save();

    expect(collect(app(PurchaseNeeds::class)->needs())
        ->where('source', 'Inventory Replenishment')
        ->where('sku', $variant->sku))
        ->toBeEmpty();

    expect($targetStock->refresh()->available_quantity)->toBe('10.000000');
});
