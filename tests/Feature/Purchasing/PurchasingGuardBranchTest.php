<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Models\InventoryOperation;
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
use App\Policies\PurchaseOrderLinePolicy;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Purchasing\Exceptions\ConfirmationNotAmendable;
use App\Services\Purchasing\Exceptions\InvalidPurchaseOrderLine;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotEditable;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotReceivable;
use App\Services\Purchasing\PurchaseOrderApprovalService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\PurchasingReportService;
use App\Services\Purchasing\SupplierConfirmationService;
use Carbon\CarbonImmutable;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/*
 * The second half of the dual checkpoint (R-G).
 *
 * Most service guards sit behind a policy that refuses first, so they are
 * unreachable through a page — which is exactly why they need their own tests.
 * A console command, a queued job, or a future API would arrive without ever
 * passing the policy, and these are the rules that would be all that stood
 * between them and a rewritten commitment.
 *
 * `Gate::before` is used deliberately to reach past the policy layer. It is not
 * a shortcut around authorization: PurchasePermissionTest covers the policy
 * answers, and these cover what happens when the policy is not the thing doing
 * the refusing.
 */

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();
    $this->actor = User::factory()->create();
    $this->actor->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($this->actor);
});

it('refuses a receipt against a non-receivable order at the service layer', function (): void {
    Gate::before(static fn (): bool => true);

    foreach ([PurchaseOrderStatus::Draft, PurchaseOrderStatus::Approved, PurchaseOrderStatus::Received] as $status) {
        $order = PurchaseOrder::factory()->create(['status' => $status]);

        expect(fn () => app(PurchaseOrderReceivingService::class)->initiate($this->actor, $order))
            ->toThrow(PurchaseOrderNotReceivable::class, $order->purchase_order_number);
    }
});

it('omits a fully received line when pre-filling a further receipt', function (): void {
    // Reached only when one line is filled and another is not; a receipt for the
    // whole order is refused earlier, so this branch has no page-level route.
    $order = PurchaseOrder::factory()->sent()->create([
        'destination_warehouse_id' => Warehouse::factory()->create()->getKey(),
    ]);

    $filledVariant = ProductVariant::factory()->create();
    $filled = $order->lines()->create([
        'product_variant_id' => $filledVariant->getKey(),
        'unit_id' => $filledVariant->unit_id,
        'quantity_ordered' => 4,
        'unit_cost' => '1.00',
    ]);
    $filled->forceFill(['quantity_received' => 4])->save();

    $outstandingVariant = ProductVariant::factory()->create();
    $outstanding = $order->lines()->create([
        'product_variant_id' => $outstandingVariant->getKey(),
        'unit_id' => $outstandingVariant->unit_id,
        'quantity_ordered' => 6,
        'unit_cost' => '1.00',
    ]);

    $operation = app(PurchaseOrderReceivingService::class)->initiate($this->actor, $order->refresh());

    expect($operation->lines)->toHaveCount(1)
        ->and($operation->lines->first()->product_variant_id)->toBe($outstanding->product_variant_id);
});

it('refuses to amend an answered confirmation at the service layer', function (): void {
    Gate::before(static fn (): bool => true);

    $confirmation = SupplierConfirmation::factory()->confirmed()->create();

    expect(fn (): SupplierConfirmation => app(SupplierConfirmationService::class)->answer(
        $this->actor,
        $confirmation,
        SupplierConfirmationStatus::Rejected,
    ))->toThrow(ConfirmationNotAmendable::class);
});

it('accepts a promised date on a customer order, which has no ordered-at column', function (): void {
    // The other half of the ordered-at branch: a purchase order carries its own
    // date, a customer order falls back to its creation timestamp.
    $customerOrder = Order::factory()->create();
    $supplier = Supplier::factory()->create();
    $service = app(SupplierConfirmationService::class);

    $confirmation = $service->record($this->actor, $customerOrder, $supplier->getKey());

    $answered = $service->answer(
        $this->actor,
        $confirmation,
        SupplierConfirmationStatus::Confirmed,
        CarbonImmutable::now()->addWeek(),
    );

    expect($answered->confirmation_status)->toBe(SupplierConfirmationStatus::Confirmed);
});

it('re-validates the supplier when a draft header changes', function (): void {
    $order = PurchaseOrder::factory()->create();
    $inactive = Supplier::factory()->create(['is_active' => false]);

    expect(fn (): PurchaseOrder => app(PurchaseOrderService::class)->updateDraft($this->actor, $order, [
        'supplier_id' => $inactive->getKey(),
    ]))->toThrow(InvalidPurchaseOrderLine::class, $inactive->name);
});

it('exempts the System Admin role itself from the self-approval rule', function (): void {
    // The other branch of isSystemAdmin(): a user who holds the System Admin
    // role rather than one who holds no scoped role at all.
    PurchaseSetting::factory()->threshold('10.00')->create();

    $admin = User::factory()->create();
    $admin->assignRole(DashboardRole::SystemAdmin->value);
    $this->actingAs($admin);

    $order = PurchaseOrder::factory()->create(['total_amount' => '500.00']);
    $variant = ProductVariant::factory()->create();
    $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => 1,
        'unit_cost' => '500.00',
        'line_total' => '500.00',
    ]);

    $service = app(PurchaseOrderApprovalService::class);
    $submitted = $service->submit($admin, $order->refresh());

    expect($service->approve($admin, $submitted)->status)->toBe(PurchaseOrderStatus::Approved);
});

it('refuses to submit a non-draft at the service layer', function (): void {
    Gate::before(static fn (): bool => true);

    $sent = PurchaseOrder::factory()->sent()->create();

    expect(fn (): PurchaseOrder => app(PurchaseOrderApprovalService::class)->submit($this->actor, $sent))
        ->toThrow(PurchaseOrderNotEditable::class);
});

it('leaves a terminal order alone when a late receipt completes against it', function (): void {
    // A short-closed order should not be resurrected by a receipt that finishes
    // afterwards, so the listener's transition guard declines silently.
    $order = PurchaseOrder::factory()->sent()->create([
        'destination_warehouse_id' => Warehouse::factory()->create()->getKey(),
    ]);

    $variant = ProductVariant::factory()->create();
    $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => 5,
        'unit_cost' => '2.00',
    ]);

    $operation = app(PurchaseOrderReceivingService::class)->initiate($this->actor, $order->refresh());
    app(InventoryOperationService::class)->markReady($operation, $this->actor);

    // Closed after the receipt was opened but before it completed.
    $order->forceFill(['status' => PurchaseOrderStatus::Closed, 'closed_at' => now()])->save();

    app(InventoryOperationService::class)->complete($operation->refresh(), $this->actor);

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Closed)
        // The quantity is still recorded — what arrived, arrived.
        ->and((float) $order->lines()->firstOrFail()->quantity_received)->toBe(5.0);
});

it('ignores a receipt line whose variant is not on the order', function (): void {
    $order = PurchaseOrder::factory()->sent()->create([
        'destination_warehouse_id' => Warehouse::factory()->create()->getKey(),
    ]);

    $orderedVariant = ProductVariant::factory()->create();
    $line = $order->lines()->create([
        'product_variant_id' => $orderedVariant->getKey(),
        'unit_id' => $orderedVariant->unit_id,
        'quantity_ordered' => 3,
        'unit_cost' => '4.00',
    ]);

    $operation = app(PurchaseOrderReceivingService::class)->initiate($this->actor, $order->refresh());

    // An unrelated variant added to the receipt by the warehouse: stock still
    // moves for it, but no purchase order line can claim it.
    $unrelatedVariant = ProductVariant::factory()->create();
    $operation->lines()->create([
        'product_variant_id' => $unrelatedVariant->getKey(),
        'unit_id' => $unrelatedVariant->unit_id,
        'quantity' => 9,
        'unit_cost' => 1,
    ]);

    app(InventoryOperationService::class)->markReady($operation->refresh(), $this->actor);
    app(InventoryOperationService::class)->complete($operation->refresh(), $this->actor);

    expect((float) $line->refresh()->quantity_received)->toBe(3.0);
});

it('writes back nothing for a line the receipt did not cover', function (): void {
    $order = PurchaseOrder::factory()->sent()->create([
        'destination_warehouse_id' => Warehouse::factory()->create()->getKey(),
    ]);

    $coveredVariant = ProductVariant::factory()->create();
    $covered = $order->lines()->create([
        'product_variant_id' => $coveredVariant->getKey(),
        'unit_id' => $coveredVariant->unit_id,
        'quantity_ordered' => 2,
        'unit_cost' => '5.00',
    ]);

    $untouchedVariant = ProductVariant::factory()->create();
    $untouched = $order->lines()->create([
        'product_variant_id' => $untouchedVariant->getKey(),
        'unit_id' => $untouchedVariant->unit_id,
        'quantity_ordered' => 2,
        'unit_cost' => '5.00',
    ]);

    $operation = app(PurchaseOrderReceivingService::class)->initiate($this->actor, $order->refresh());
    // Drop the second line from the receipt entirely.
    $operation->lines()->where('product_variant_id', $untouched->product_variant_id)->delete();

    app(InventoryOperationService::class)->markReady($operation->refresh(), $this->actor);
    app(InventoryOperationService::class)->complete($operation->refresh(), $this->actor);

    expect(SupplierProductReference::query()->where('product_variant_id', $covered->product_variant_id)->exists())->toBeTrue()
        ->and(SupplierProductReference::query()->where('product_variant_id', $untouched->product_variant_id)->exists())->toBeFalse();
});

it('excludes a confirmation whose order has no completed receipt from receiving performance', function (): void {
    $supplier = Supplier::factory()->create();
    $order = PurchaseOrder::factory()->sent()->create(['supplier_id' => $supplier->getKey()]);

    SupplierConfirmation::factory()->create([
        'confirmable_type' => PurchaseOrder::class,
        'confirmable_id' => $order->getKey(),
        'supplier_id' => $supplier->getKey(),
        'confirmation_status' => SupplierConfirmationStatus::Confirmed,
        'promised_at' => today()->toDateString(),
        'confirmed_at' => now(),
    ]);

    expect(app(PurchasingReportService::class)->receivingPerformance())->toBe([]);
});

it('excludes a confirmation attached to a customer order from receiving performance', function (): void {
    // The report measures supplier delivery against purchase orders; a customer
    // order has no receipt of its own to score.
    $supplier = Supplier::factory()->create();

    SupplierConfirmation::factory()->create([
        'confirmable_type' => Order::class,
        'confirmable_id' => Order::factory()->create()->getKey(),
        'supplier_id' => $supplier->getKey(),
        'confirmation_status' => SupplierConfirmationStatus::Confirmed,
        'promised_at' => today()->toDateString(),
        'confirmed_at' => now(),
    ]);

    expect(app(PurchasingReportService::class)->receivingPerformance())->toBe([]);
});

it('answers every read ability on a purchase order line from its parent order', function (): void {
    $draftOrder = PurchaseOrder::factory()->create();
    /** @var PurchaseOrderLine $draftLine */
    $draftLine = $draftOrder->lines()->create([
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity_ordered' => 1,
        'unit_cost' => '1.00',
    ]);

    $sentOrder = PurchaseOrder::factory()->sent()->create();
    /** @var PurchaseOrderLine $sentLine */
    $sentLine = $sentOrder->lines()->create([
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity_ordered' => 1,
        'unit_cost' => '1.00',
    ]);

    expect($this->actor->can('viewAny', PurchaseOrderLine::class))->toBeTrue()
        ->and($this->actor->can('view', $draftLine))->toBeTrue()
        ->and($this->actor->can('create', PurchaseOrderLine::class))->toBeTrue()
        ->and($this->actor->can('update', $draftLine))->toBeTrue()
        ->and($this->actor->can('delete', $draftLine))->toBeTrue()
        // Inherited from the parent: a sent order's lines are frozen too.
        ->and($this->actor->can('update', $sentLine))->toBeFalse()
        ->and($this->actor->can('delete', $sentLine))->toBeFalse()
        ->and($this->actor->can('forceDelete', $draftLine))->toBeFalse()
        ->and((new PurchaseOrderLinePolicy)->forceDelete())->toBeFalse();
});

it('answers the single-record read ability on every purchasing record', function (): void {
    // `view` is distinct from `viewAny` in every policy, and a resource's view
    // page is the only thing that consults it.
    $order = PurchaseOrder::factory()->create();
    $confirmation = SupplierConfirmation::factory()->create();
    $supplier = Supplier::factory()->create();
    $reference = SupplierProductReference::factory()->create();
    $setting = PurchaseSetting::factory()->create();

    expect($this->actor->can('view', $order))->toBeTrue()
        ->and($this->actor->can('view', $confirmation))->toBeTrue()
        ->and($this->actor->can('view', $supplier))->toBeTrue()
        ->and($this->actor->can('view', $reference))->toBeTrue()
        // The threshold is System Admin only, so a manager cannot read it either.
        ->and($this->actor->can('view', $setting))->toBeFalse();
});

it('lets a System Admin create and restore what a manager cannot', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(DashboardRole::SystemAdmin->value);

    $supplier = Supplier::factory()->create();
    $reference = SupplierProductReference::factory()->create();

    expect($admin->can('create', PurchaseSetting::class))->toBeTrue()
        ->and($admin->can('restore', $supplier))->toBeTrue()
        ->and($admin->can('restore', $reference))->toBeTrue()
        ->and($this->actor->can('restore', $supplier))->toBeFalse()
        ->and($this->actor->can('restore', $reference))->toBeFalse();
});

it('refuses an ability the permission map does not name', function (): void {
    // The map is the whole vocabulary. An ability outside it is denied rather
    // than defaulting open, so a typo in a policy method hides a surface instead
    // of exposing one.
    $order = PurchaseOrder::factory()->create();

    expect(Gate::forUser($this->actor)->check('reticulateSplines', $order))->toBeFalse();
});

it('refuses to delete a supplier that has canonical receipt operations but no purchase orders', function (): void {
    // The middle branch of the delete guard, carried over from CatalogPolicy.
    $supplier = Supplier::factory()->create();
    InventoryOperation::factory()->receipt()->create(['supplier_id' => $supplier->getKey()]);

    expect($this->actor->can('delete', $supplier))->toBeFalse();
});
