<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Models\AuditLog;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseSetting;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderApprovalService;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * FR-048 through FR-050. Last-paid price, not a moving average: averaging needs
 * landed cost, which this feature places out of scope, and a misleading average
 * is worse than a plain figure that says what it is (R-009).
 *
 * Writeback fires at PO acceptance (Phase 0 remediation), not receipt
 * completion: a receipt line carries no cost — Inventory/Logistics owns zero
 * monetary data — so the order line's own frozen unit_cost, normalized to the
 * variant's base UOM via its conversion_factor_snapshot, is the only cost
 * signal left by the time an order is accepted.
 */

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();
    $this->service = app(PurchaseOrderApprovalService::class);
    $this->manager = User::factory()->create();
    $this->manager->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($this->manager);
});

/**
 * Builds a draft order with a single base-UOM line carrying the given cost,
 * ready to accept via submit's auto-approval.
 *
 * @return array{0: PurchaseOrder, 1: Supplier, 2: ProductVariant}
 */
function orderForWriteback(string $unitCost = '10.00', string $currency = 'AED'): array
{
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->create();

    $order = PurchaseOrder::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'currency_code' => $currency,
    ]);

    // conversion_factor_snapshot and line_total are not mass-assignable
    // (data-model.md §10: they're written only by the service, never by the
    // form), so the base-UOM snapshot writeback depends on is set separately.
    $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => 4,
        'unit_cost' => $unitCost,
        'line_total' => (float) $unitCost * 4,
    ])->forceFill([
        'conversion_factor_snapshot' => '1.000000',
    ])->save();

    return [$order->refresh(), $supplier, $variant];
}

/**
 * Accepts an order in one step: a threshold generous enough, and expressed in
 * the order's own currency, that submit's own auto-approval is the acceptance
 * event — which is where writeback now fires.
 */
function acceptViaAutoApproval(PurchaseOrderApprovalService $service, User $actor, PurchaseOrder $order): PurchaseOrder
{
    PurchaseSetting::factory()->threshold('999999.00', $order->currency_code)->create();

    return $service->submit($actor, $order);
}

it('overwrites the existing reference cost with what was actually paid (FR-048)', function (): void {
    [$order, $supplier, $variant] = orderForWriteback('12.50');

    $reference = SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => '10.00',
        'currency_code' => 'AED',
    ]);

    acceptViaAutoApproval($this->service, $this->manager, $order);

    expect($reference->refresh()->purchase_cost)->toBe('12.50');
});

it('creates an active reference when the supplier had none for that variant (FR-049)', function (): void {
    // Without this, a variant first bought on an ad-hoc order would never gain a
    // reference, and every future order for it would keep defaulting to zero.
    [$order, $supplier, $variant] = orderForWriteback('9.75');

    expect(SupplierProductReference::query()->count())->toBe(0);

    acceptViaAutoApproval($this->service, $this->manager, $order);

    $created = SupplierProductReference::query()->sole();

    expect($created->supplier_id)->toBe($supplier->getKey())
        ->and($created->product_variant_id)->toBe($variant->getKey())
        ->and($created->purchase_cost)->toBe('9.75')
        ->and($created->is_active)->toBeTrue()
        ->and($created->supplier_item_number)->toBe($variant->sku);
});

it('follows the order currency without converting anything (FR-050)', function (): void {
    [$order, $supplier, $variant] = orderForWriteback('11.00', 'USD');

    $reference = SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => '10.00',
        'currency_code' => 'AED',
    ]);

    acceptViaAutoApproval($this->service, $this->manager, $order);

    $reference->refresh();

    // A reference re-costed from a USD order is a USD reference. Converting
    // would require a rate this feature does not have.
    expect($reference->currency_code)->toBe('USD')
        ->and($reference->purchase_cost)->toBe('11.00');
});

it('ignores an inactive reference and creates an active one beside it', function (): void {
    [$order, $supplier, $variant] = orderForWriteback('8.00');

    $inactive = SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => '99.00',
        'is_active' => false,
    ]);

    acceptViaAutoApproval($this->service, $this->manager, $order);

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
    [$order, $supplier, $variant] = orderForWriteback('12.50');

    $reference = SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => '10.00',
    ]);

    acceptViaAutoApproval($this->service, $this->manager, $order);

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

it('writes nothing back when a line carries no conversion-factor snapshot', function (): void {
    // Inventory/Logistics owns zero monetary data, and a base-UOM cost cannot
    // be derived without the factor that normalizes the line's transaction-UOM
    // cost — so a line missing its snapshot is skipped rather than mis-costed.
    [$order, $supplier, $variant] = orderForWriteback('10.00');
    $order->lines()->firstOrFail()->update(['conversion_factor_snapshot' => null]);

    $reference = SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => '10.00',
    ]);

    acceptViaAutoApproval($this->service, $this->manager, $order);

    expect($reference->refresh()->purchase_cost)->toBe('10.00');
});
