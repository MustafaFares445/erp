<?php

declare(strict_types=1);

use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseSetting;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Every purchasing relation, read from both ends.
 *
 * These look trivial, and they are — but a morph pointed at the wrong column
 * name or a foreign key spelled `supplier` instead of `supplier_id` fails
 * silently by returning an empty set, and every report and infolist above them
 * would simply show nothing. Reading each one once is what turns that from a
 * silent wrong answer into a failing test.
 */

it('reads a purchase order from its supplier and warehouse, and back again', function (): void {
    $supplier = Supplier::factory()->create();
    $warehouse = Warehouse::factory()->create();

    $order = PurchaseOrder::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'destination_warehouse_id' => $warehouse->getKey(),
    ]);

    expect($order->supplier->is($supplier))->toBeTrue()
        ->and($order->destinationWarehouse->is($warehouse))->toBeTrue()
        ->and($supplier->purchaseOrders()->pluck('id')->all())->toBe([$order->getKey()]);
});

it('reads the submitter and approver off an order', function (): void {
    $submitter = User::factory()->create();
    $approver = User::factory()->create();

    $order = PurchaseOrder::factory()->create();
    $order->forceFill([
        'submitted_by' => $submitter->getKey(),
        'approved_by' => $approver->getKey(),
    ])->save();

    $order->refresh();

    expect($order->submittedBy?->is($submitter))->toBeTrue()
        ->and($order->approvedBy?->is($approver))->toBeTrue();
});

it('reads a line back to its order, variant, unit, and price provenance', function (): void {
    $order = PurchaseOrder::factory()->create();
    $variant = ProductVariant::factory()->create();
    $unit = Unit::factory()->create();
    $reference = SupplierProductReference::factory()->create([
        'supplier_id' => $order->supplier_id,
        'product_variant_id' => $variant->getKey(),
    ]);

    /** @var PurchaseOrderLine $line */
    $line = $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'supplier_product_reference_id' => $reference->getKey(),
        'quantity_ordered' => 2,
        'unit_cost' => '10.00',
    ]);

    expect($line->purchaseOrder->is($order))->toBeTrue()
        ->and($line->productVariant->is($variant))->toBeTrue()
        ->and($line->unit->is($unit))->toBeTrue()
        ->and($line->supplierProductReference?->is($reference))->toBeTrue();
});

it('reports a line as fully received only when nothing is outstanding', function (): void {
    $order = PurchaseOrder::factory()->create();

    /** @var PurchaseOrderLine $line */
    $line = $order->lines()->create([
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity_ordered' => 5,
        'unit_cost' => '1.00',
    ]);

    expect($line->isFullyReceived())->toBeFalse()
        ->and($line->outstandingQuantity())->toBe(5.0);

    $line->forceFill(['quantity_received' => 5])->save();

    expect($line->refresh()->isFullyReceived())->toBeTrue()
        ->and($line->outstandingQuantity())->toBe(0.0);
});

it('reads confirmations from a supplier, a purchase order, and a customer order', function (): void {
    $supplier = Supplier::factory()->create();
    $purchaseOrder = PurchaseOrder::factory()->create(['supplier_id' => $supplier->getKey()]);
    $customerOrder = Order::factory()->create();

    $onPurchase = SupplierConfirmation::factory()->create([
        'confirmable_type' => PurchaseOrder::class,
        'confirmable_id' => $purchaseOrder->getKey(),
        'supplier_id' => $supplier->getKey(),
    ]);

    $onCustomer = SupplierConfirmation::factory()->create([
        'confirmable_type' => Order::class,
        'confirmable_id' => $customerOrder->getKey(),
        'supplier_id' => $supplier->getKey(),
    ]);

    expect($supplier->confirmations()->pluck('id')->all())->toBe([$onPurchase->getKey(), $onCustomer->getKey()])
        ->and($purchaseOrder->confirmations()->pluck('id')->all())->toBe([$onPurchase->getKey()])
        ->and($customerOrder->confirmations()->pluck('id')->all())->toBe([$onCustomer->getKey()])
        // Both ends of the morph resolve, which is what makes one record type
        // serve two documents (R-007).
        ->and($onPurchase->confirmable?->is($purchaseOrder))->toBeTrue()
        ->and($onCustomer->confirmable?->is($customerOrder))->toBeTrue();
});

it('reads the user who answered a confirmation', function (): void {
    $actor = User::factory()->create();
    $confirmation = SupplierConfirmation::factory()->confirmed($actor)->create();

    expect($confirmation->confirmedBy?->is($actor))->toBeTrue()
        ->and($confirmation->isAnswered())->toBeTrue();
});

it('scopes an active reference to one supplier and variant, ignoring inactive rows', function (): void {
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->create();

    $active = SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'is_active' => true,
    ]);

    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'is_active' => false,
    ]);

    expect(SupplierProductReference::query()->activeFor($supplier->getKey(), $variant->getKey())->pluck('id')->all())
        ->toBe([$active->getKey()]);
});

it('returns the singleton settings row, creating it once', function (): void {
    $first = PurchaseSetting::current();
    $second = PurchaseSetting::current();

    expect($first->is($second))->toBeTrue()
        ->and(PurchaseSetting::query()->count())->toBe(1)
        ->and($first->approval_threshold_amount)->toBe('0.00')
        ->and($first->approval_threshold_currency)->toBe('AED');
});

it('reports no rejected confirmation when an order has none at all', function (): void {
    expect(PurchaseOrder::factory()->create()->hasRejectedConfirmation())->toBeFalse();
});

it('labels every purchasing enum case in English', function (): void {
    foreach (PurchaseOrderStatus::cases() as $status) {
        expect($status->label())->not->toBe('admin.purchasing.order_status.'.$status->value, $status->value);
    }

    foreach (SupplierConfirmationStatus::cases() as $status) {
        expect($status->label())->not->toBe('admin.purchasing.confirmation_status.'.$status->value, $status->value);
    }
});
