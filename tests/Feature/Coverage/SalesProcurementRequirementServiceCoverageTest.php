<?php

declare(strict_types=1);

use App\Events\SalesOrderReleased;
use App\Models\Currency;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SalesProcurementRequirement;
use App\Models\User;
use App\Services\Sales\SalesProcurementRequirementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('does nothing for a non-released order', function (): void {
    $order = Order::factory()->draft()->create();

    $requirements = app(SalesProcurementRequirementService::class)->synchronize($order);

    expect($requirements)->toBeEmpty()
        ->and(SalesProcurementRequirement::query()->count())->toBe(0);
});

it('creates an open procurement requirement for uncovered released demand', function (): void {
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->create();
    $line = OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 10,
        'unit_id' => $variant->unit_id,
    ]);

    $requirements = app(SalesProcurementRequirementService::class)->synchronize($order);

    expect($requirements)->toHaveCount(1);
    $requirement = $requirements->first();
    expect($requirement)->toBeInstanceOf(SalesProcurementRequirement::class)
        ->and((int) $requirement->order_line_id)->toBe($line->getKey())
        ->and((float) $requirement->required_base_quantity)->toBe(10.0)
        ->and((float) $requirement->fulfilled_base_quantity)->toBe(0.0)
        ->and($requirement->status)->toBe('open');
});

it('cancels an unlinked requirement when stock now covers the remaining demand', function (): void {
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->create();
    $line = OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 8,
        'unit_id' => $variant->unit_id,
    ]);
    $requirement = $order->procurementRequirements()->create([
        'order_line_id' => $line->getKey(),
        'product_variant_id' => $variant->getKey(),
        'required_base_quantity' => 8,
        'fulfilled_base_quantity' => 0,
        'status' => 'open',
    ]);

    InventoryStock::factory()->for($variant, 'productVariant')->create([
        'on_hand_quantity' => 8,
        'reserved_quantity' => 0,
        'available_quantity' => 8,
    ]);

    $requirements = app(SalesProcurementRequirementService::class)->synchronize($order);

    expect($requirements)->toBeEmpty()
        ->and($requirement->refresh()->status)->toBe('cancelled');
});

it('records an audit activity when an actor synchronizes requirements', function (): void {
    $actor = User::factory()->create();
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->create();
    OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 3,
        'unit_id' => $variant->unit_id,
    ]);

    app(SalesProcurementRequirementService::class)->synchronize($order, $actor);

    expect(Activity::query()
        ->where('causer_id', $actor->getKey())
        ->where('subject_id', $order->getKey())
        ->latest('id')
        ->value('description'))
        ->toBe('sales.order.procurement_requirements_synchronized');
});

it('requeues only the outstanding Sales quantity while preserving the failed PO attempt as history', function (): void {
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $orderLine = OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 5,
        'unit_id' => $variant->unit_id,
    ]);
    $purchaseOrder = PurchaseOrder::factory()->sent()->create();
    $purchaseLine = PurchaseOrderLine::factory()->for($purchaseOrder)->for($variant, 'productVariant')->create([
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => 5,
    ]);
    $purchaseLine->forceFill(['received_base_quantity' => '2.000000'])->save();

    $historical = $order->procurementRequirements()->create([
        'order_line_id' => $orderLine->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_order_id' => $purchaseOrder->getKey(),
        'purchase_order_line_id' => $purchaseLine->getKey(),
        'required_base_quantity' => 5,
        'fulfilled_base_quantity' => 0,
        'status' => 'purchasing',
    ]);

    $service = app(SalesProcurementRequirementService::class);
    $requeued = $service->requeueFromPurchaseOrder($purchaseOrder, 'Supplier rejected the balance');

    expect($requeued)->toHaveCount(1)
        ->and($historical->refresh()->status)->toBe('superseded')
        ->and((float) $historical->fulfilled_base_quantity)->toBe(2.0)
        ->and($historical->notes)->toContain('Supplier rejected the balance');

    $replacement = $requeued->sole();

    expect($replacement->status)->toBe('open')
        ->and((float) $replacement->required_base_quantity)->toBe(3.0)
        ->and((float) $replacement->fulfilled_base_quantity)->toBe(0.0)
        ->and($replacement->purchase_order_id)->toBeNull()
        ->and($replacement->purchase_order_line_id)->toBeNull();

    expect($service->requeueFromPurchaseOrder($purchaseOrder, 'Repeated callback'))->toBeEmpty()
        ->and($order->procurementRequirements()->where('status', 'open')->count())->toBe(1);
});

it('marks a fully covered Sales procurement attempt fulfilled instead of requeueing it', function (): void {
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $orderLine = OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 2,
        'unit_id' => $variant->unit_id,
    ]);
    $purchaseOrder = PurchaseOrder::factory()->sent()->create();
    $purchaseLine = PurchaseOrderLine::factory()->for($purchaseOrder)->for($variant, 'productVariant')->create([
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => 2,
    ]);
    $purchaseLine->forceFill(['received_base_quantity' => '2.000000'])->save();

    $requirement = $order->procurementRequirements()->create([
        'order_line_id' => $orderLine->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_order_id' => $purchaseOrder->getKey(),
        'purchase_order_line_id' => $purchaseLine->getKey(),
        'required_base_quantity' => 2,
        'fulfilled_base_quantity' => 0,
        'status' => 'purchasing',
    ]);

    $requeued = app(SalesProcurementRequirementService::class)
        ->requeueFromPurchaseOrder($purchaseOrder, 'Short close after complete receipt');

    expect($requeued)->toBeEmpty()
        ->and($requirement->refresh()->status)->toBe('fulfilled')
        ->and((float) $requirement->fulfilled_base_quantity)->toBe(2.0);
});

it('refreshes purchasing-linked procurement requirements from received purchase quantity', function (): void {
    Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        ['name' => 'UAE Dirham', 'is_active' => true, 'is_default' => true],
    );
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $orderLine = OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 5,
        'unit_id' => $variant->unit_id,
    ]);
    $purchaseOrder = PurchaseOrder::factory()->create(['currency_code' => 'AED']);
    $purchaseLine = PurchaseOrderLine::factory()->for($purchaseOrder)->for($variant, 'productVariant')->create([
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => 5,
    ]);
    $purchaseLine->forceFill(['received_base_quantity' => 3])->save();

    $requirement = $order->procurementRequirements()->create([
        'order_line_id' => $orderLine->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_order_id' => $purchaseOrder->getKey(),
        'purchase_order_line_id' => $purchaseLine->getKey(),
        'required_base_quantity' => 5,
        'fulfilled_base_quantity' => 0,
        'status' => 'purchasing',
    ]);

    $service = app(SalesProcurementRequirementService::class);
    $service->refreshFromPurchaseOrder($purchaseOrder);

    expect((float) $requirement->refresh()->fulfilled_base_quantity)->toBe(3.0)
        ->and($requirement->status)->toBe('purchasing');

    $purchaseLine->forceFill(['received_base_quantity' => 5])->save();
    $service->refreshFromPurchaseOrder($purchaseOrder);

    expect((float) $requirement->refresh()->fulfilled_base_quantity)->toBe(5.0)
        ->and($requirement->status)->toBe('fulfilled');
});

it('automatically synchronizes Sales procurement when a released-order event is dispatched', function (): void {
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->create();

    OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 4,
        'unit_id' => $variant->unit_id,
    ]);

    SalesOrderReleased::dispatch($order->fresh());

    $requirement = SalesProcurementRequirement::query()->where('order_id', $order->id)->sole();

    expect($requirement->status)->toBe('open')
        ->and((float) $requirement->required_base_quantity)->toBe(4.0)
        ->and($requirement->purchase_order_id)->toBeNull();
});

it('preserves a failed PO attempt and requeues only its remaining Sales demand', function (): void {
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->create();
    $orderLine = OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 10,
        'unit_id' => $variant->unit_id,
    ]);
    $purchaseOrder = PurchaseOrder::factory()->create();
    $purchaseLine = PurchaseOrderLine::factory()
        ->for($purchaseOrder)
        ->for($variant, 'productVariant')
        ->create([
            'unit_id' => $variant->unit_id,
            'quantity_ordered' => 10,
        ]);
    $purchaseLine->forceFill([
        'base_quantity' => '10.000000',
        'received_base_quantity' => '3.000000',
    ])->save();

    $attempt = $order->procurementRequirements()->create([
        'order_line_id' => $orderLine->id,
        'product_variant_id' => $variant->id,
        'purchase_order_id' => $purchaseOrder->id,
        'purchase_order_line_id' => $purchaseLine->id,
        'required_base_quantity' => '10.000000',
        'fulfilled_base_quantity' => '0.000000',
        'status' => 'purchasing',
    ]);

    $requeued = app(SalesProcurementRequirementService::class)->requeueFromPurchaseOrder(
        $purchaseOrder,
        'Supplier rejected the remaining demand',
    );

    expect($attempt->refresh()->status)->toBe('superseded')
        ->and((float) $attempt->fulfilled_base_quantity)->toBe(3.0)
        ->and($requeued)->toHaveCount(1);

    $replacement = $requeued->sole();

    expect($replacement->status)->toBe('open')
        ->and($replacement->purchase_order_id)->toBeNull()
        ->and((float) $replacement->required_base_quantity)->toBe(7.0)
        ->and((float) $replacement->fulfilled_base_quantity)->toBe(0.0);

    expect(app(SalesProcurementRequirementService::class)->requeueFromPurchaseOrder(
        $purchaseOrder,
        'Repeated terminal callback',
    ))->toBeEmpty();
});
