<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseOrderStatus;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchasing\Exceptions\InvalidPurchaseOrderLine;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotEditable;
use App\Services\Purchasing\PurchaseOrderService;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();
    $this->service = app(PurchaseOrderService::class);
    $this->buyer = User::factory()->create();
    $this->buyer->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($this->buyer);
});

function draftFor(User $buyer, PurchaseOrderService $service, ?Supplier $supplier = null): PurchaseOrder
{
    return $service->createDraft($buyer, [
        'supplier_id' => ($supplier ?? Supplier::factory()->create())->getKey(),
        'destination_warehouse_id' => Warehouse::factory()->create()->getKey(),
        'currency_code' => 'aed',
        'ordered_at' => now()->toDateString(),
    ]);
}

it('creates a draft with a generated number and an upper-cased currency', function (): void {
    $order = draftFor($this->buyer, $this->service);

    expect($order->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($order->purchase_order_number)->toBe('PO-000001')
        ->and($order->currency_code)->toBe('AED')
        ->and($order->total_amount)->toBe('0.00')
        ->and($order->created_by)->toBe($this->buyer->getKey());
});

it('refuses to draft against an inactive supplier or an inactive warehouse (V-01, V-02)', function (): void {
    $inactiveSupplier = Supplier::factory()->create(['is_active' => false]);
    $inactiveWarehouse = Warehouse::factory()->create(['is_active' => false]);

    expect(fn (): PurchaseOrder => $this->service->createDraft($this->buyer, [
        'supplier_id' => $inactiveSupplier->getKey(),
        'destination_warehouse_id' => Warehouse::factory()->create()->getKey(),
        'currency_code' => 'AED',
        'ordered_at' => now()->toDateString(),
    ]))->toThrow(InvalidPurchaseOrderLine::class, $inactiveSupplier->name);

    expect(fn (): PurchaseOrder => $this->service->createDraft($this->buyer, [
        'supplier_id' => Supplier::factory()->create()->getKey(),
        'destination_warehouse_id' => $inactiveWarehouse->getKey(),
        'currency_code' => 'AED',
        'ordered_at' => now()->toDateString(),
    ]))->toThrow(InvalidPurchaseOrderLine::class, $inactiveWarehouse->name);
});

it('defaults a line cost from the supplier product reference and snapshots its provenance (FR-013)', function (): void {
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->create();
    $reference = SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => '17.50',
        'supplier_item_number' => 'ACME-991',
    ]);

    $order = draftFor($this->buyer, $this->service, $supplier);

    $line = $this->service->addLine($this->buyer, $order, [
        'product_variant_id' => $variant->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity_ordered' => 4,
    ]);

    expect($line->unit_cost)->toBe('17.50')
        ->and($line->supplier_product_reference_id)->toBe($reference->getKey())
        // The item number is snapshotted, so the order still records what it was
        // drafted from after a later receipt re-costs the reference.
        ->and($line->supplier_item_number)->toBe('ACME-991')
        ->and($line->line_total)->toBe('70.00');
});

it('falls back to zero when the supplier has no reference for the variant', function (): void {
    $order = draftFor($this->buyer, $this->service);

    $line = $this->service->addLine($this->buyer, $order, [
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity_ordered' => 3,
    ]);

    expect($line->unit_cost)->toBe('0.00')
        ->and($line->supplier_product_reference_id)->toBeNull()
        ->and($line->line_total)->toBe('0.00');
});

it('ignores an inactive reference when defaulting cost', function (): void {
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->create();
    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => '99.00',
        'is_active' => false,
    ]);

    $order = draftFor($this->buyer, $this->service, $supplier);

    $line = $this->service->addLine($this->buyer, $order, [
        'product_variant_id' => $variant->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity_ordered' => 1,
    ]);

    expect($line->unit_cost)->toBe('0.00');
});

it('prefers an explicitly given cost over the reference', function (): void {
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->create();
    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => '17.50',
    ]);

    $order = draftFor($this->buyer, $this->service, $supplier);

    $line = $this->service->addLine($this->buyer, $order, [
        'product_variant_id' => $variant->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity_ordered' => 2,
        'unit_cost' => '15.00',
    ]);

    expect($line->unit_cost)->toBe('15.00')
        ->and($line->line_total)->toBe('30.00');
});

it('rejects a second line for the same variant and unit (FR-014, V-05)', function (): void {
    $order = draftFor($this->buyer, $this->service);
    $variant = ProductVariant::factory()->create();
    $unit = Unit::factory()->create();

    $this->service->addLine($this->buyer, $order, [
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity_ordered' => 1,
    ]);

    expect(fn () => $this->service->addLine($this->buyer, $order, [
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity_ordered' => 5,
    ]))->toThrow(InvalidPurchaseOrderLine::class, $variant->sku);
});

it('permits the same variant twice in different units, which the unique index scopes on', function (): void {
    $order = draftFor($this->buyer, $this->service);
    $variant = ProductVariant::factory()->create();

    $this->service->addLine($this->buyer, $order, [
        'product_variant_id' => $variant->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity_ordered' => 1,
    ]);

    $second = $this->service->addLine($this->buyer, $order, [
        'product_variant_id' => $variant->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity_ordered' => 2,
    ]);

    expect($order->refresh()->lines)->toHaveCount(2)
        ->and($second->exists)->toBeTrue();
});

it('refuses a non-positive quantity and a negative cost (V-04)', function (): void {
    $order = draftFor($this->buyer, $this->service);

    $attributes = [
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
    ];

    expect(fn () => $this->service->addLine($this->buyer, $order, [...$attributes, 'quantity_ordered' => 0]))
        ->toThrow(InvalidPurchaseOrderLine::class);

    expect(fn () => $this->service->addLine($this->buyer, $order, [...$attributes, 'quantity_ordered' => -3]))
        ->toThrow(InvalidPurchaseOrderLine::class);

    expect(fn () => $this->service->addLine($this->buyer, $order, [...$attributes, 'quantity_ordered' => 1, 'unit_cost' => -1]))
        ->toThrow(InvalidPurchaseOrderLine::class);
});

it('recomputes the document total from stored line totals on every line write (R-008)', function (): void {
    $order = draftFor($this->buyer, $this->service);

    $first = $this->service->addLine($this->buyer, $order, [
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity_ordered' => 2,
        'unit_cost' => '10.00',
    ]);

    expect($order->refresh()->total_amount)->toBe('20.00');

    $second = $this->service->addLine($this->buyer, $order, [
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity_ordered' => 3,
        'unit_cost' => '5.00',
    ]);

    expect($order->refresh()->total_amount)->toBe('35.00');

    $this->service->updateLine($this->buyer, $first, ['quantity_ordered' => 4]);
    expect($order->refresh()->total_amount)->toBe('55.00');

    $this->service->removeLine($this->buyer, $second);
    expect($order->refresh()->total_amount)->toBe('40.00');
});

it('keeps the document total equal to the sum of the figures printed on it', function (): void {
    // Each line total is rounded and stored, and the document total sums the
    // stored figures. Re-deriving from quantity times cost would drift from what
    // the buyer sees on the page.
    $order = draftFor($this->buyer, $this->service);

    foreach ([1, 2, 3] as $ignored) {
        $this->service->addLine($this->buyer, $order, [
            'product_variant_id' => ProductVariant::factory()->create()->getKey(),
            'unit_id' => Unit::factory()->create()->getKey(),
            'quantity_ordered' => 3,
            'unit_cost' => '33.333',
        ]);
    }

    $order->refresh();
    // Summed in minor units, because summing decimal strings as PHP floats is
    // exactly the drift this invariant exists to rule out: 3 x 99.99 comes back
    // as 299.96999999999997 in binary floating point.
    $sumOfPrintedLines = $order->lines->sum(fn ($line): int => (int) round((float) $line->line_total * 100));

    expect((int) round((float) $order->total_amount * 100))->toBe($sumOfPrintedLines)
        ->and($order->lines->first()->unit_cost)->toBe('33.33')
        ->and($order->lines->first()->line_total)->toBe('99.99')
        ->and($order->total_amount)->toBe('299.97');
});

it('refuses every mutation once the order has left draft (FR-025, V-06)', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();
    $line = $order->lines()->create([
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity_ordered' => 1,
        'unit_cost' => 1,
    ]);

    // The policy refuses first — a sent order is not updatable by anyone — so
    // the authorization layer is what a caller hits, and the service guard
    // behind it is proven separately in PurchaseOrderImmutabilityTest.
    expect(fn () => $this->service->updateDraft($this->buyer, $order, ['notes' => 'late change']))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $this->service->addLine($this->buyer, $order, [
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity_ordered' => 1,
    ]))->toThrow(AuthorizationException::class);

    expect(fn () => $this->service->updateLine($this->buyer, $line, ['quantity_ordered' => 99]))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $this->service->removeLine($this->buyer, $line))
        ->toThrow(AuthorizationException::class);
});

it('refuses a service-level edit even when the policy is bypassed (R-G)', function (): void {
    // The dual checkpoint: a caller that got past the page guard still cannot
    // write, because the service re-checks the status itself.
    $order = PurchaseOrder::factory()->sent()->create();

    expect(fn () => $this->service->assertEditable($order))
        ->toThrow(PurchaseOrderNotEditable::class, $order->purchase_order_number);
});

it('refuses drafting to a user without the manage permission', function (): void {
    $reviewer = User::factory()->create();
    $reviewer->assignRole(DashboardRole::Reviewer->value);

    expect(fn (): PurchaseOrder => draftFor($reviewer, $this->service))
        ->toThrow(AuthorizationException::class);
});

it('updates a draft header and re-validates the supplier and warehouse', function (): void {
    $order = draftFor($this->buyer, $this->service);
    $newWarehouse = Warehouse::factory()->create();

    $updated = $this->service->updateDraft($this->buyer, $order, [
        'destination_warehouse_id' => $newWarehouse->getKey(),
        'notes' => 'Split delivery agreed by phone',
    ]);

    expect($updated->destination_warehouse_id)->toBe($newWarehouse->getKey())
        ->and($updated->notes)->toBe('Split delivery agreed by phone')
        ->and($updated->updated_by)->toBe($this->buyer->getKey());

    $inactive = Warehouse::factory()->create(['is_active' => false]);

    expect(fn (): PurchaseOrder => $this->service->updateDraft($this->buyer, $order, [
        'destination_warehouse_id' => $inactive->getKey(),
    ]))->toThrow(InvalidPurchaseOrderLine::class, $inactive->name);
});
