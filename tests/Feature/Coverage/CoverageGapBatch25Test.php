<?php

declare(strict_types=1);

use App\Enums\SupplierConfirmationStatus;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Models\SupplierConfirmationItem;
use App\Services\Purchasing\PurchaseOrderSupplierCommitmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers supplier commitment detached-line and transaction conversion branches', function (): void {
    $service = app(PurchaseOrderSupplierCommitmentService::class);

    $detached = new PurchaseOrderLine;
    $detached->forceFill([
        'base_quantity' => '1.000000',
        'quantity_ordered' => '1.000',
    ]);

    expect(fn (): array => $service->quantities($detached))
        ->toThrow(LogicException::class, 'Purchase order line has no purchase order.');

    $supplier = Supplier::factory()->create(['requires_confirmation' => false]);
    $order = PurchaseOrder::factory()->for($supplier)->create();
    $variant = ProductVariant::factory()->create();

    $line = PurchaseOrderLine::factory()->for($order, 'purchaseOrder')->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'base_quantity' => null,
        'transaction_quantity' => '2.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '3.000000',
        'quantity_ordered' => '2.000',
    ]);

    expect($service->quantities($line)['ordered'])->toBe('6.000000');
});

it('covers supplier commitment pending and exhausted evidence branches', function (): void {
    $service = app(PurchaseOrderSupplierCommitmentService::class);

    $supplier = Supplier::factory()->create(['requires_confirmation' => true]);
    $order = PurchaseOrder::factory()->for($supplier)->create();
    $variant = ProductVariant::factory()->create();
    $line = PurchaseOrderLine::factory()->for($order, 'purchaseOrder')->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'base_quantity' => '10.000000',
        'quantity_ordered' => '10.000',
    ]);

    $pendingConfirmation = SupplierConfirmation::factory()->for($order, 'purchaseOrder')->create();
    SupplierConfirmationItem::factory()->for($pendingConfirmation, 'confirmation')->create([
        'purchase_order_line_id' => $line->getKey(),
        'product_variant_id' => $variant->getKey(),
        'requested_base_quantity' => '10.000000',
        'confirmation_status' => SupplierConfirmationStatus::Pending,
    ]);

    $pending = $service->quantities($line->fresh());

    expect($pending['confirmed'])->toBe('0.000000')
        ->and($pending['awaiting_confirmation'])->toBeTrue();

    SupplierConfirmationItem::query()->delete();

    $answeredConfirmation = SupplierConfirmation::factory()->for($order, 'purchaseOrder')->create();
    SupplierConfirmationItem::factory()->for($answeredConfirmation, 'confirmation')->create([
        'purchase_order_line_id' => $line->getKey(),
        'product_variant_id' => $variant->getKey(),
        'requested_base_quantity' => '10.000000',
        'confirmed_base_quantity' => '10.000000',
        'confirmation_status' => SupplierConfirmationStatus::Confirmed,
    ]);
    $followUpConfirmation = SupplierConfirmation::factory()->for($order, 'purchaseOrder')->create();
    SupplierConfirmationItem::factory()->for($followUpConfirmation, 'confirmation')->create([
        'purchase_order_line_id' => $line->getKey(),
        'product_variant_id' => $variant->getKey(),
        'requested_base_quantity' => '1.000000',
        'confirmed_base_quantity' => '1.000000',
        'confirmation_status' => SupplierConfirmationStatus::Confirmed,
    ]);

    $answered = $service->quantities($line->fresh());

    expect($answered['confirmed'])->toBe('10.000000')
        ->and($answered['backordered'])->toBe('0.000000');
});

it('covers supplier commitment negative cap guard', function (): void {
    $method = new ReflectionMethod(PurchaseOrderSupplierCommitmentService::class, 'capAtOrdered');

    expect($method->invoke(app(PurchaseOrderSupplierCommitmentService::class), '-1.000000', '5.000000'))
        ->toBe('0.000000');
});
