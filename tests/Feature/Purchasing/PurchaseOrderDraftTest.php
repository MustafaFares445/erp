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
use App\Services\Inventory\ProductVariantUomService;
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
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => 4,
    ]);

    expect($line->unit_cost)->toBe('17.50')
        ->and($line->supplier_product_reference_id)->toBe($reference->getKey())
        ->and($line->supplier_item_number)->toBe('ACME-991')
        ->and($line->line_total)->toBe('70.00');
});

it('snapshots a configured purchase UOM and scales the supplier reference cost before receiving', function (): void {
    $piece = Unit::factory()->whole()->create([
        'code' => 'PO-DRAFT-PIECE',
        'name' => 'PO draft piece',
        'symbol' => 'PDP',
        'family' => 'count',
    ]);
    $box = Unit::factory()->whole()->create([
        'code' => 'PO-DRAFT-BOX',
        'name' => 'PO draft box',
        'symbol' => 'PDB',
        'family' => 'count',
    ]);
    $variant = ProductVariant::factory()->create(['unit_id' => $piece->getKey()]);
    app(ProductVariantUomService::class)->sync($variant, [
        [
            'unit_id' => $piece->getKey(),
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
            'unit_id' => $box->getKey(),
            'is_base' => false,
            'is_purchase' => true,
            'is_sale' => false,
            'is_display' => false,
            'factor_to_base' => '100',
            'rounding_increment' => '1',
            'permits_cross_family_conversion' => false,
            'is_active' => true,
        ],
    ]);

    $supplier = Supplier::factory()->create();
    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => '2.00',
    ]);
    $order = draftFor($this->buyer, $this->service, $supplier);

    $line = $this->service->addLine($this->buyer, $order, [
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $box->getKey(),
        'quantity_ordered' => 3,
    ]);

    expect($line->quantity_ordered)->toBe('3.000000')
        ->and($line->transaction_quantity)->toBe('3.000000')
        ->and($line->transaction_unit_id)->toBe($box->getKey())
        ->and($line->conversion_factor_snapshot)->toBe('100.000000')
        ->and($line->base_quantity)->toBe('300.000000')
        ->and($line->received_base_quantity)->toBe('0.000000')
        ->and($line->unit_cost)->toBe('200.00')
        ->and($line->line_total)->toBe('600.00');
});

it('rejects a variant that has no active reference for the selected supplier', function (): void {
    $order = draftFor($this->buyer, $this->service);
    $attributes = purchaseDraftProductUnit();

    expect(fn () => $this->service->addLine($this->buyer, $order, [
        ...$attributes,
        'quantity_ordered' => 3,
        'unit_cost' => '12.00',
    ]))->toThrow(InvalidPurchaseOrderLine::class, 'does not have an active product reference');

    expect($order->lines()->count())->toBe(0);
});

it('rejects an inactive supplier product reference', function (): void {
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->create();
    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => '99.00',
        'is_active' => false,
    ]);

    $order = draftFor($this->buyer, $this->service, $supplier);

    expect(fn () => $this->service->addLine($this->buyer, $order, [
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => 1,
    ]))->toThrow(InvalidPurchaseOrderLine::class, 'does not have an active product reference');
});

it('prefers an explicitly given cost over the active supplier reference', function (): void {
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
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => 2,
        'unit_cost' => '15.00',
    ]);

    expect($line->unit_cost)->toBe('15.00')
        ->and($line->line_total)->toBe('30.00');
});

it('returns only active variants supported by the order supplier for the picker', function (): void {
    $supplier = Supplier::factory()->create();
    $otherSupplier = Supplier::factory()->create();
    $supported = ProductVariant::factory()->create();
    $inactive = ProductVariant::factory()->create();
    $other = ProductVariant::factory()->create();

    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $supported->getKey(),
        'supplier_item_number' => 'SUP-100',
        'is_active' => true,
    ]);
    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $inactive->getKey(),
        'supplier_item_number' => 'SUP-200',
        'is_active' => false,
    ]);
    SupplierProductReference::factory()->create([
        'supplier_id' => $otherSupplier->getKey(),
        'product_variant_id' => $other->getKey(),
        'supplier_item_number' => 'OTHER-100',
        'is_active' => true,
    ]);

    $order = draftFor($this->buyer, $this->service, $supplier);
    $options = $this->service->supportedVariantOptions($order);

    expect($options)->toHaveCount(1)
        ->and($options)->toHaveKey($supported->getKey())
        ->and($options[$supported->getKey()])->toContain((string) $supported->sku)
        ->toContain('SUP-100');
});

it('blocks changing the supplier while draft lines still carry its commercial provenance', function (): void {
    $order = draftFor($this->buyer, $this->service);
    $attributes = purchaseDraftProductUnit($order);

    $this->service->addLine($this->buyer, $order, [
        ...$attributes,
        'quantity_ordered' => 1,
    ]);

    $replacementSupplier = Supplier::factory()->create();

    expect(fn () => $this->service->updateDraft($this->buyer, $order, [
        'supplier_id' => $replacementSupplier->getKey(),
    ]))->toThrow(InvalidPurchaseOrderLine::class, 'Remove all purchase-order lines');
});

it('allows changing the supplier before any line is added', function (): void {
    $order = draftFor($this->buyer, $this->service);
    $replacementSupplier = Supplier::factory()->create();

    $updated = $this->service->updateDraft($this->buyer, $order, [
        'supplier_id' => $replacementSupplier->getKey(),
    ]);

    expect($updated->supplier_id)->toBe($replacementSupplier->getKey());
});

it('rejects a second line for the same variant and unit (FR-014, V-05)', function (): void {
    $order = draftFor($this->buyer, $this->service);
    $variant = ProductVariant::factory()->create();
    $unit = $variant->unit()->firstOrFail();
    SupplierProductReference::factory()->create([
        'supplier_id' => $order->supplier_id,
        'product_variant_id' => $variant->getKey(),
    ]);

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

it('permits the same variant twice in different configured purchase units', function (): void {
    $order = draftFor($this->buyer, $this->service);
    $variant = ProductVariant::factory()->create();
    $baseUnit = $variant->unit()->firstOrFail();
    $alternateUnit = Unit::factory()->create([
        'family' => $baseUnit->family,
        'allows_decimal' => $baseUnit->allows_decimal,
        'precision' => $baseUnit->precision,
    ]);

    $variant->product?->addAllowedUnit($alternateUnit);
    $variant->variantUnits()->create([
        'unit_id' => $alternateUnit->getKey(),
        'is_base' => false,
        'is_purchase' => true,
        'is_sale' => false,
        'is_display' => false,
        'factor_to_base' => '2.000000',
        'rounding_increment' => $alternateUnit->precision === 0 ? '1.000000' : '0.001000',
        'permits_cross_family_conversion' => false,
        'is_active' => true,
        'effective_from' => now(),
    ]);
    SupplierProductReference::factory()->create([
        'supplier_id' => $order->supplier_id,
        'product_variant_id' => $variant->getKey(),
    ]);

    $this->service->addLine($this->buyer, $order, [
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $baseUnit->getKey(),
        'quantity_ordered' => 1,
    ]);

    $second = $this->service->addLine($this->buyer, $order, [
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $alternateUnit->getKey(),
        'quantity_ordered' => 2,
    ]);

    expect($order->refresh()->lines)->toHaveCount(2)
        ->and($second->exists)->toBeTrue()
        ->and($second->transaction_unit_id)->toBe($alternateUnit->getKey())
        ->and($second->conversion_factor_snapshot)->toBe('2.000000')
        ->and($second->base_quantity)->toBe('4.000000');
});

it('refuses a non-positive quantity and a negative cost (V-04)', function (): void {
    $order = draftFor($this->buyer, $this->service);
    $attributes = purchaseDraftProductUnit($order);

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
        ...purchaseDraftProductUnit($order),
        'quantity_ordered' => 2,
        'unit_cost' => '10.00',
    ]);

    expect($order->refresh()->total_amount)->toBe('20.00');

    $second = $this->service->addLine($this->buyer, $order, [
        ...purchaseDraftProductUnit($order),
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
    $order = draftFor($this->buyer, $this->service);

    foreach ([1, 2, 3] as $ignored) {
        $this->service->addLine($this->buyer, $order, [
            ...purchaseDraftProductUnit($order),
            'quantity_ordered' => 3,
            'unit_cost' => '33.333',
        ]);
    }

    $order->refresh();
    $sumOfPrintedLines = $order->lines->sum(fn ($line): int => (int) round((float) $line->line_total * 100));

    expect((int) round((float) $order->total_amount * 100))->toBe($sumOfPrintedLines)
        ->and($order->lines->first()->unit_cost)->toBe('33.33')
        ->and($order->lines->first()->line_total)->toBe('99.99')
        ->and($order->total_amount)->toBe('299.97');
});

it('refuses every mutation once the order has left draft (FR-025, V-06)', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();
    $line = $order->lines()->create([
        ...purchaseDraftProductUnit(),
        'quantity_ordered' => 1,
        'unit_cost' => 1,
    ]);

    expect(fn () => $this->service->updateDraft($this->buyer, $order, ['notes' => 'late change']))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $this->service->addLine($this->buyer, $order, [
        ...purchaseDraftProductUnit(),
        'quantity_ordered' => 1,
    ]))->toThrow(AuthorizationException::class);

    expect(fn () => $this->service->updateLine($this->buyer, $line, ['quantity_ordered' => 99]))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $this->service->removeLine($this->buyer, $line))
        ->toThrow(AuthorizationException::class);
});

it('refuses a service-level edit even when the policy is bypassed (R-G)', function (): void {
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

it('updates a draft header', function (): void {
    $order = draftFor($this->buyer, $this->service);

    $updated = $this->service->updateDraft($this->buyer, $order, [
        'notes' => 'Split delivery agreed by phone',
    ]);

    expect($updated->notes)->toBe('Split delivery agreed by phone')
        ->and($updated->updated_by)->toBe($this->buyer->getKey());
});

/** @return array{product_variant_id: int, unit_id: int} */
function purchaseDraftProductUnit(?PurchaseOrder $order = null): array
{
    $variant = ProductVariant::factory()->create();

    if (! is_int($variant->unit_id)) {
        throw new LogicException('Purchase-order test variants require an integer base unit.');
    }

    if ($order instanceof PurchaseOrder) {
        SupplierProductReference::factory()->create([
            'supplier_id' => $order->supplier_id,
            'product_variant_id' => $variant->getKey(),
            'purchase_cost' => '1.00',
            'is_active' => true,
        ]);
    }

    return [
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
    ];
}
