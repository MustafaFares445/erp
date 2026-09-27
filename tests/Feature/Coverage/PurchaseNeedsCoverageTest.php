<?php

declare(strict_types=1);

use App\Filament\Pages\PurchaseNeeds;
use App\Models\Currency;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\SupplierProductSupport;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

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

    $reference = SupplierProductReference::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'is_active' => true,
    ]);
    SupplierProductSupport::factory()->create([
        'supplier_id' => $reference->supplier_id,
        'product_variant_id' => $variant->getKey(),
        'product_id' => null,
        'is_active' => true,
    ]);

    $row = collect(app(PurchaseNeeds::class)->needs())
        ->firstWhere('source', 'Sales Order');

    expect($row)->not->toBeNull()
        ->and($row['source_reference'])->toBe($order->order_number)
        ->and($row['source_url'])->toBeString()->not->toBe('')
        ->and($row['sku'])->toBe($variant->sku)
        ->and($row['warehouse'])->toBe($warehouse->name)
        ->and($row['remaining'])->toBe('3.000000')
        ->and($row['linked_po'])->toBe($purchaseOrder->purchase_order_number)
        ->and($row['linked_po_url'])->toBeString()->not->toBe('')
        ->and($row['next_action'])->toBe('Review linked Purchase Order')
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

    $reference = SupplierProductReference::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'is_active' => true,
    ]);
    SupplierProductSupport::factory()->create([
        'supplier_id' => $reference->supplier_id,
        'product_variant_id' => $variant->getKey(),
        'product_id' => null,
        'is_active' => true,
    ]);

    $row = collect(app(PurchaseNeeds::class)->needs())
        ->firstWhere('source', 'Inventory Replenishment');

    expect($row)->not->toBeNull()
        ->and($row['source_url'])->toBeNull()
        ->and($row['sku'])->toBe($variant->sku)
        ->and($row['warehouse'])->toBe($target->name)
        ->and($row['remaining'])->toBe('50.000000')
        ->and($row['next_action'])->toBe('Create Purchase Order')
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

it('creates Purchase Order drafts from a Sales demand action', function (): void {
    Gate::before(static fn (): bool => true);

    Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        ['name' => 'UAE Dirham', 'is_active' => true, 'is_default' => true],
    );

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $line = OrderLine::factory()
        ->for($order)
        ->for($variant, 'productVariant')
        ->create([
            'quantity' => 3,
            'unit_id' => $variant->unit_id,
        ]);

    $order->procurementRequirements()->create([
        'order_line_id' => $line->getKey(),
        'product_variant_id' => $variant->getKey(),
        'required_base_quantity' => 3,
        'fulfilled_base_quantity' => 0,
        'status' => 'open',
    ]);

    $supplier = Supplier::factory()->create();
    SupplierProductSupport::factory()
        ->for($supplier)
        ->for($variant, 'productVariant')
        ->create();
    SupplierProductReference::factory()
        ->for($supplier)
        ->for($variant, 'productVariant')
        ->create([
            'currency_code' => 'AED',
            'purchase_cost' => '15.00',
            'is_active' => true,
        ]);

    $page = app(PurchaseNeeds::class);
    $method = new ReflectionMethod($page, 'getHeaderActions');
    $actions = $method->invoke($page);
    expect($actions)->toBeArray();

    $action = collect($actions)->first(
        static fn (mixed $candidate): bool => $candidate instanceof Action
            && $candidate->getName() === 'createFromSalesDemand',
    );

    expect($action)->toBeInstanceOf(Action::class);

    if ($action instanceof Action === false) {
        return;
    }

    $callback = $action->getActionFunction();
    expect($callback)->not->toBeNull();

    $callback([
        'order_id' => $order->getKey(),
        'supplier_id' => $supplier->getKey(),
        'currency_code' => 'AED',
    ]);

    $requirement = $order->procurementRequirements()->firstOrFail();

    expect($requirement->refresh()->purchase_order_id)->not->toBeNull()
        ->and(PurchaseOrder::query()->whereKey($requirement->purchase_order_id)->exists())->toBeTrue();
});

it('does not count a catalog reference as an eligible supplier without active capability', function (): void {
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->create();
    $line = OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 1,
        'unit_id' => $variant->unit_id,
    ]);

    $order->procurementRequirements()->create([
        'order_line_id' => $line->getKey(),
        'product_variant_id' => $variant->getKey(),
        'required_base_quantity' => 1,
        'fulfilled_base_quantity' => 0,
        'status' => 'open',
    ]);

    SupplierProductReference::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'is_active' => true,
    ]);

    $row = collect(app(PurchaseNeeds::class)->needs())
        ->firstWhere('source_reference', $order->order_number);

    expect($row)->not->toBeNull()
        ->and($row['supplier_count'])->toBe(0);
});

it('counts product-wide supplier capability when no variant-specific capability exists', function (): void {
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->create();
    $line = OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 1,
        'unit_id' => $variant->unit_id,
    ]);

    $order->procurementRequirements()->create([
        'order_line_id' => $line->getKey(),
        'product_variant_id' => $variant->getKey(),
        'required_base_quantity' => 1,
        'fulfilled_base_quantity' => 0,
        'status' => 'open',
    ]);

    $reference = SupplierProductReference::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'is_active' => true,
    ]);

    SupplierProductSupport::factory()->create([
        'supplier_id' => $reference->supplier_id,
        'product_id' => $variant->product_id,
        'product_variant_id' => null,
        'is_active' => true,
    ]);

    $row = collect(app(PurchaseNeeds::class)->needs())
        ->firstWhere('source_reference', $order->order_number);

    expect($row)->not->toBeNull()
        ->and($row['supplier_count'])->toBe(1);
});

it('does not create Sales-demand drafts when the Purchase Needs action has no authenticated actor', function (): void {
    $order = Order::factory()->create();

    $page = app(PurchaseNeeds::class);
    $method = new ReflectionMethod($page, 'getHeaderActions');
    $actions = $method->invoke($page);

    $action = collect($actions)->first(
        static fn (mixed $candidate): bool => $candidate instanceof Action
            && $candidate->getName() === 'createFromSalesDemand',
    );

    expect($action)->toBeInstanceOf(Action::class);

    if ($action instanceof Action === false) {
        return;
    }

    auth()->logout();

    $callback = $action->getActionFunction();
    expect($callback)->not->toBeNull();

    $before = PurchaseOrder::query()->count();

    $callback([
        'order_id' => $order->getKey(),
        'supplier_id' => Supplier::factory()->create()->getKey(),
        'currency_code' => 'AED',
    ]);

    expect(PurchaseOrder::query()->count())->toBe($before);
});
