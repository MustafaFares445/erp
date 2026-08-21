<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Models\AuditLog;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * FR-048 through FR-050. Last-paid price, not a moving average: averaging needs
 * landed cost, which this feature places out of scope, and a misleading average
 * is worse than a plain figure that says what it is (R-009).
 */

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();
    $this->receiving = app(PurchaseOrderReceivingService::class);
    $this->operations = app(InventoryOperationService::class);
    $this->manager = User::factory()->create();
    $this->manager->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($this->manager);
});

/**
 * Receives an order at a given actual cost and returns the order.
 */
function receiveAt(
    PurchaseOrderReceivingService $receiving,
    InventoryOperationService $operations,
    User $actor,
    PurchaseOrder $order,
    float $actualCost,
): PurchaseOrder {
    $operation = $receiving->initiate($actor, $order);
    $operation->lines()->firstOrFail()->update(['unit_cost' => $actualCost]);
    $operations->markReady($operation->refresh(), $actor);
    $operations->complete($operation->refresh(), $actor);

    return $order->refresh();
}

/**
 * @return array{0: PurchaseOrder, 1: Supplier, 2: ProductVariant}
 */
function orderForWriteback(string $orderedCost = '10.00', string $currency = 'AED'): array
{
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->create();

    $order = PurchaseOrder::factory()->sent()->create([
        'supplier_id' => $supplier->getKey(),
        'destination_warehouse_id' => Warehouse::factory()->create()->getKey(),
        'currency_code' => $currency,
    ]);

    $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity_ordered' => 4,
        'unit_cost' => $orderedCost,
        'line_total' => (float) $orderedCost * 4,
    ]);

    return [$order->refresh(), $supplier, $variant];
}

it('overwrites the existing reference cost with what was actually paid (FR-048)', function (): void {
    [$order, $supplier, $variant] = orderForWriteback('10.00');

    $reference = SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => '10.00',
        'currency_code' => 'AED',
    ]);

    receiveAt($this->receiving, $this->operations, $this->manager, $order, 12.5);

    expect($reference->refresh()->purchase_cost)->toBe('12.50');
});

it('creates an active reference when the supplier had none for that variant (FR-049)', function (): void {
    // Without this, a variant first bought on an ad-hoc order would never gain a
    // reference, and every future order for it would keep defaulting to zero.
    [$order, $supplier, $variant] = orderForWriteback('10.00');

    expect(SupplierProductReference::query()->count())->toBe(0);

    receiveAt($this->receiving, $this->operations, $this->manager, $order, 9.75);

    $created = SupplierProductReference::query()->sole();

    expect($created->supplier_id)->toBe($supplier->getKey())
        ->and($created->product_variant_id)->toBe($variant->getKey())
        ->and($created->purchase_cost)->toBe('9.75')
        ->and($created->is_active)->toBeTrue()
        ->and($created->supplier_item_number)->toBe($variant->sku);
});

it('follows the order currency without converting anything (FR-050)', function (): void {
    [$order, $supplier, $variant] = orderForWriteback('10.00', 'USD');

    $reference = SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => '10.00',
        'currency_code' => 'AED',
    ]);

    receiveAt($this->receiving, $this->operations, $this->manager, $order, 11.0);

    $reference->refresh();

    // A reference re-costed from a USD order is a USD reference. Converting
    // would require a rate this feature does not have.
    expect($reference->currency_code)->toBe('USD')
        ->and($reference->purchase_cost)->toBe('11.00');
});

it('ignores an inactive reference and creates an active one beside it', function (): void {
    [$order, $supplier, $variant] = orderForWriteback('10.00');

    $inactive = SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => '99.00',
        'is_active' => false,
    ]);

    receiveAt($this->receiving, $this->operations, $this->manager, $order, 8.0);

    expect($inactive->refresh()->purchase_cost)->toBe('99.00')
        ->and(SupplierProductReference::query()->where('is_active', true)->sole()->purchase_cost)->toBe('8.00');
});

it('refuses a second active reference for the same supplier and variant (V-14)', function (): void {
    // The unique index is what makes cost writeback unambiguous: with two active
    // rows there would be no single target to update.
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->create();

    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'is_active' => true,
    ]);

    expect(fn (): SupplierProductReference => SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'is_active' => true,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('permits any number of inactive references for the same supplier and variant', function (): void {
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->create();

    foreach (range(1, 3) as $ignored) {
        SupplierProductReference::factory()->create([
            'supplier_id' => $supplier->getKey(),
            'product_variant_id' => $variant->getKey(),
            'is_active' => false,
        ]);
    }

    expect(SupplierProductReference::query()->count())->toBe(3);
});

it('records the previous cost in the audit log rather than a history table (R-009)', function (): void {
    [$order, $supplier, $variant] = orderForWriteback('10.00');

    $reference = SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => '10.00',
    ]);

    receiveAt($this->receiving, $this->operations, $this->manager, $order, 12.5);

    $activity = AuditLog::query()
        ->where('subject_type', SupplierProductReference::class)
        ->where('subject_id', $reference->getKey())
        ->where('description', 'purchasing.supplier_reference.recosted')
        ->sole();

    // Spatie stores withChanges() in `attribute_changes`, separately from the
    // `properties` bag withProperties() writes.
    expect($activity->attribute_changes['old']['purchase_cost'] ?? null)->toBe('10.00')
        ->and($activity->attribute_changes['attributes']['purchase_cost'] ?? null)->toBe('12.50')
        ->and($activity->properties['purchase_order_number'] ?? null)->toBe($order->purchase_order_number);
});

it('writes nothing back when the receipt recorded no cost', function (): void {
    [$order, $supplier, $variant] = orderForWriteback('10.00');

    $reference = SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => '10.00',
    ]);

    $operation = $this->receiving->initiate($this->manager, $order);
    $operation->lines()->firstOrFail()->update(['unit_cost' => null]);
    $this->operations->markReady($operation->refresh(), $this->manager);
    $this->operations->complete($operation->refresh(), $this->manager);

    expect($reference->refresh()->purchase_cost)->toBe('10.00');
});
