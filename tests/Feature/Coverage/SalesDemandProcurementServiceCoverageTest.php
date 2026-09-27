<?php

declare(strict_types=1);

use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\SupplierProductSupport;
use App\Models\User;
use App\Services\Purchasing\SalesDemandProcurementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('creates purchasing PO drafts from supported open sales demand', function (): void {
    Gate::before(static fn (): bool => true);
    Currency::query()->firstOrCreate(
        ['code' => 'USD'],
        ['name' => 'US Dollar', 'is_active' => true, 'is_default' => false],
    );
    $actor = User::factory()->create();
    $supplier = Supplier::factory()->create();
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $line = OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 6,
        'unit_id' => $variant->unit_id,
    ]);
    $requirement = $order->procurementRequirements()->create([
        'order_line_id' => $line->getKey(),
        'product_variant_id' => $variant->getKey(),
        'required_base_quantity' => 6,
        'fulfilled_base_quantity' => 0,
        'status' => 'open',
    ]);
    SupplierProductSupport::factory()
        ->for($supplier)
        ->for($variant, 'productVariant')
        ->create();
    SupplierProductReference::factory()
        ->for($supplier)
        ->for($variant, 'productVariant')
        ->create(['currency_code' => 'USD', 'purchase_cost' => 12.50]);

    $service = app(SalesDemandProcurementService::class);

    expect($service->eligibleSupplierIds($order))->toContain($supplier->getKey());

    $drafts = $service->createDrafts($actor, $order, $supplier->getKey(), 'USD');
    $purchaseOrder = $drafts->sole();

    expect($purchaseOrder->supplier_id)->toBe($supplier->getKey())
        ->and($purchaseOrder->currency_code)->toBe('USD')
        ->and($purchaseOrder->lines)->toHaveCount(1)
        ->and($purchaseOrder->lines->first()->product_variant_id)->toBe($variant->getKey());
    expect($requirement->refresh()->purchase_order_id)->toBe($purchaseOrder->getKey())
        ->and($requirement->purchase_order_line_id)->toBe($purchaseOrder->lines->first()->getKey())
        ->and($requirement->status)->toBe('purchasing');
});

it('rejects drafting when sales demand has no open requirements', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();
    $supplier = Supplier::factory()->create();
    $order = Order::factory()->create();

    expect(fn () => app(SalesDemandProcurementService::class)
        ->createDrafts($actor, $order, $supplier->getKey(), 'AED'))
        ->toThrow(DomainException::class, 'There are no open Sales procurement requirements.');
});

it('rejects a supplier that cannot support the selected sales demand', function (): void {
    Gate::before(static fn (): bool => true);

    $actor = User::factory()->create();
    $unsupportedSupplier = Supplier::factory()->create();
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $line = OrderLine::factory()
        ->for($order)
        ->for($variant, 'productVariant')
        ->create([
            'quantity' => 2,
            'unit_id' => $variant->unit_id,
        ]);

    $order->procurementRequirements()->create([
        'order_line_id' => $line->getKey(),
        'product_variant_id' => $variant->getKey(),
        'required_base_quantity' => 2,
        'fulfilled_base_quantity' => 0,
        'status' => 'open',
    ]);
    expect(fn () => app(SalesDemandProcurementService::class)->createDrafts(
        $actor,
        $order,
        $unsupportedSupplier->getKey(),
        'AED',
    ))->toThrow(
        DomainException::class,
        'selected supplier does not have an active commercial reference in the selected currency for every open Sales demand line',
    );
});

it('filters Sales-demand suppliers by active commercial reference currency', function (): void {
    Gate::before(static fn (): bool => true);

    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $line = OrderLine::factory()
        ->for($order)
        ->for($variant, 'productVariant')
        ->create([
            'quantity' => 2,
            'unit_id' => $variant->unit_id,
        ]);

    $order->procurementRequirements()->create([
        'order_line_id' => $line->getKey(),
        'product_variant_id' => $variant->getKey(),
        'required_base_quantity' => 2,
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
            'currency_code' => 'USD',
            'purchase_cost' => 12.50,
            'is_active' => true,
        ]);

    $service = app(SalesDemandProcurementService::class);

    expect($service->eligibleSupplierIds($order))->toContain($supplier->getKey())
        ->and($service->eligibleSupplierIds($order, 'USD'))->toContain($supplier->getKey())
        ->and($service->eligibleSupplierIds($order, 'AED'))->not->toContain($supplier->getKey());
});

it('keeps product-wide and variant-specific supplier capability additive and ignores inactive suppliers', function (): void {
    Gate::before(static fn (): bool => true);

    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $line = OrderLine::factory()
        ->for($order)
        ->for($variant, 'productVariant')
        ->create([
            'quantity' => 2,
            'unit_id' => $variant->unit_id,
        ]);

    $order->procurementRequirements()->create([
        'order_line_id' => $line->getKey(),
        'product_variant_id' => $variant->getKey(),
        'required_base_quantity' => 2,
        'fulfilled_base_quantity' => 0,
        'status' => 'open',
    ]);

    $variantSupplier = Supplier::factory()->create();
    SupplierProductSupport::factory()
        ->for($variantSupplier)
        ->for($variant, 'productVariant')
        ->create();
    SupplierProductReference::factory()
        ->for($variantSupplier)
        ->for($variant, 'productVariant')
        ->create(['currency_code' => 'USD', 'is_active' => true]);

    $productSupplier = Supplier::factory()->create();
    SupplierProductSupport::factory()->create([
        'supplier_id' => $productSupplier->getKey(),
        'product_id' => $variant->product_id,
        'product_variant_id' => null,
        'is_active' => true,
    ]);
    SupplierProductReference::factory()
        ->for($productSupplier)
        ->for($variant, 'productVariant')
        ->create(['currency_code' => 'USD', 'is_active' => true]);

    $inactiveSupplier = Supplier::factory()->create(['is_active' => false]);
    SupplierProductSupport::factory()
        ->for($inactiveSupplier)
        ->for($variant, 'productVariant')
        ->create();
    SupplierProductReference::factory()
        ->for($inactiveSupplier)
        ->for($variant, 'productVariant')
        ->create(['currency_code' => 'USD', 'is_active' => true]);

    $eligible = app(SalesDemandProcurementService::class)->eligibleSupplierIds($order, 'USD');

    expect($eligible)
        ->toContain($variantSupplier->getKey())
        ->toContain($productSupplier->getKey())
        ->not->toContain($inactiveSupplier->getKey());
});

