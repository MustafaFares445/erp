<?php

declare(strict_types=1);

use App\Enums\BillStatus;
use App\Enums\DashboardRole;
use App\Enums\PurchaseOrderStatus;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseSetting;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchasing\Exceptions\InvalidPurchaseOrderLine;
use App\Services\Purchasing\Exceptions\PurchaseOrderAlreadyConcluded;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotCancellable;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotEditable;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotYetAccepted;
use App\Services\Purchasing\Exceptions\SelfApprovalRejected;
use App\Services\Purchasing\PurchaseOrderApprovalService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new ChartOfAccountsSeeder)->run();
    (new PurchasePermissionSeeder)->run();
    $this->service = app(PurchaseOrderApprovalService::class);
    $this->manager = User::factory()->create();
    $this->manager->assignRole(DashboardRole::PurchasingManager->value);

    $this->officer = User::factory()->create();
    $this->officer->assignRole(DashboardRole::PurchasingOfficer->value);
    $this->actingAs($this->manager);
});

function orderWithLines(string $total = '100.00', string $currency = 'AED'): PurchaseOrder
{
    $order = PurchaseOrder::factory()->create(['currency_code' => $currency, 'total_amount' => $total]);
    $unit = Unit::factory()->create();
    $variant = ProductVariant::factory()->create(['unit_id' => $unit->getKey()]);

    SupplierProductReference::factory()->create([
        'supplier_id' => $order->supplier_id,
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => $total,
        'currency_code' => $currency,
    ]);

    $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity_ordered' => 1,
        'unit_cost' => $total,
        'line_total' => $total,
    ])->forceFill([
        'transaction_quantity' => '1.000000',
        'transaction_unit_id' => $unit->getKey(),
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '1.000000',
        'received_base_quantity' => '0.000000',
    ])->save();

    return $order->refresh();
}

it('auto-approves a submission at or below the threshold and attributes it to the submitter (FR-020, R-004)', function (): void {
    PurchaseSetting::factory()->threshold('500.00')->create();

    $order = orderWithLines('500.00');

    $submitted = $this->service->submit($this->officer, $order);

    expect($submitted->status)->toBe(PurchaseOrderStatus::Accepted)
        ->and($submitted->submitted_by)->toBe($this->officer->getKey())
        // "Nobody approved it" is not a truthful record of who caused the state
        // change, so an auto-approval attributes to the submitter (SC-005).
        ->and($submitted->approved_by)->toBe($this->officer->getKey())
        ->and($submitted->approved_at)->not->toBeNull();

    // Auto-approval is itself an acceptance, so it must trigger supplier cost
    // writeback the same as an explicit approve() does (FR-048).
    $line = $submitted->lines()->firstOrFail();
    $reference = SupplierProductReference::query()
        ->where('supplier_id', $submitted->supplier_id)
        ->where('product_variant_id', $line->product_variant_id)
        ->sole();

    expect($reference->purchase_cost)->toBe('500.00');
});

it('routes an above-threshold submission to pending approval', function (): void {
    PurchaseSetting::factory()->threshold('500.00')->create();

    $submitted = $this->service->submit($this->officer, orderWithLines('500.01'));

    expect($submitted->status)->toBe(PurchaseOrderStatus::PendingApproval)
        ->and($submitted->approved_by)->toBeNull()
        ->and($submitted->approved_at)->toBeNull();
});

it('requires explicit approval for everything while the threshold is at its zero default', function (): void {
    // Zero is the safe default: nothing auto-approves until the owner sets a
    // real value.
    PurchaseSetting::factory()->create();

    expect($this->service->submit($this->officer, orderWithLines('0.01'))->status)
        ->toBe(PurchaseOrderStatus::PendingApproval);
});

it('routes a currency the threshold is not expressed in to explicit approval', function (): void {
    // This feature converts nothing, so comparing 100 USD against a 500 AED
    // threshold would be arithmetic on incomparable units.
    PurchaseSetting::factory()->threshold('500.00', 'AED')->create();

    $submitted = $this->service->submit($this->officer, orderWithLines('100.00', 'USD'));

    expect($submitted->status)->toBe(PurchaseOrderStatus::PendingApproval);
});

it('does not re-evaluate an order already submitted when the threshold moves (FR-024)', function (): void {
    $settings = PurchaseSetting::factory()->threshold('10.00')->create();

    $submitted = $this->service->submit($this->officer, orderWithLines('500.00'));
    expect($submitted->status)->toBe(PurchaseOrderStatus::PendingApproval);

    // Raising the threshold above the order's value must not silently approve
    // something that is already waiting for a human.
    $settings->update(['approval_threshold_amount' => '1000.00']);

    expect($submitted->refresh()->status)->toBe(PurchaseOrderStatus::PendingApproval);
});

it('refuses to submit an order with no lines (V-03)', function (): void {
    $order = PurchaseOrder::factory()->create();

    expect(fn (): PurchaseOrder => $this->service->submit($this->manager, $order))
        ->toThrow(InvalidPurchaseOrderLine::class, $order->purchase_order_number);
});

it('refuses to submit an order that is not a draft', function (): void {
    expect(fn (): PurchaseOrder => $this->service->submit($this->manager, PurchaseOrder::factory()->sent()->create()))
        ->toThrow(AuthorizationException::class);
});

it('refuses self-approval of an above-threshold order (R-005, FR-022)', function (): void {
    PurchaseSetting::factory()->threshold('10.00')->create();

    $submitted = $this->service->submit($this->manager, orderWithLines('500.00'));

    expect(fn (): PurchaseOrder => $this->service->approve($this->manager, $submitted))
        ->toThrow(SelfApprovalRejected::class, $submitted->purchase_order_number);
});

it('exempts a System Admin from the self-approval rule, so a single-admin deployment does not deadlock', function (): void {
    PurchaseSetting::factory()->threshold('10.00')->create();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $submitted = $this->service->submit($admin, orderWithLines('500.00'));
    $approved = $this->service->approve($admin, $submitted);

    expect($approved->status)->toBe(PurchaseOrderStatus::Accepted)
        ->and($approved->approved_by)->toBe($admin->getKey());
});

it('lets a different approver approve what an officer submitted', function (): void {
    PurchaseSetting::factory()->threshold('10.00')->create();

    $submitted = $this->service->submit($this->officer, orderWithLines('500.00'));
    $approved = $this->service->approve($this->manager, $submitted);

    expect($approved->status)->toBe(PurchaseOrderStatus::Accepted)
        ->and($approved->approved_by)->toBe($this->manager->getKey())
        ->and($approved->submitted_by)->toBe($this->officer->getKey());

    // approve() is the acceptance event on this path, so it — not the earlier
    // submit() into PendingApproval — is what must trigger writeback (FR-048).
    $line = $approved->lines()->firstOrFail();
    $reference = SupplierProductReference::query()
        ->where('supplier_id', $approved->supplier_id)
        ->where('product_variant_id', $line->product_variant_id)
        ->sole();

    expect($reference->purchase_cost)->toBe('500.00');
});

it('returns a rejected order to draft with the reason kept', function (): void {
    PurchaseSetting::factory()->threshold('10.00')->create();

    $submitted = $this->service->submit($this->officer, orderWithLines('500.00'));
    $rejected = $this->service->reject($this->manager, $submitted, 'Quote higher than budget');

    expect($rejected->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($rejected->rejection_reason)->toBe('Quote higher than budget')
        ->and($rejected->approved_by)->toBeNull();
});

it('clears the rejection reason when the revised order is submitted again', function (): void {
    PurchaseSetting::factory()->threshold('10.00')->create();

    $submitted = $this->service->submit($this->officer, orderWithLines('500.00'));
    $rejected = $this->service->reject($this->manager, $submitted, 'Too expensive');

    $resubmitted = $this->service->submit($this->officer, $rejected);

    expect($resubmitted->rejection_reason)->toBeNull()
        ->and($resubmitted->status)->toBe(PurchaseOrderStatus::PendingApproval);
});

it('refuses an approval a second time, so two concurrent approvers cannot both win', function (): void {
    PurchaseSetting::factory()->threshold('10.00')->create();

    $submitted = $this->service->submit($this->officer, orderWithLines('500.00'));
    $this->service->approve($this->manager, $submitted);

    // The row is locked for the transition, so the loser reads a status the
    // matrix will not move from.
    expect(fn (): PurchaseOrder => $this->service->approve($this->manager, $submitted->refresh()))
        ->toThrow(PurchaseOrderNotEditable::class);
});

it('records supplier communication metadata on an accepted order without changing its status', function (): void {
    $order = PurchaseOrder::factory()->accepted()->create();

    $sent = $this->service->send($this->manager, $order);

    expect($sent->status)->toBe(PurchaseOrderStatus::Accepted)
        ->and($sent->sent_at)->not->toBeNull();
});

it('refuses to record supplier communication on anything that has not yet been accepted', function (): void {
    foreach ([PurchaseOrderStatus::Draft, PurchaseOrderStatus::PendingApproval, PurchaseOrderStatus::Rejected] as $status) {
        $order = PurchaseOrder::factory()->create(['status' => $status]);

        expect(fn (): PurchaseOrder => $this->service->send($this->manager, $order))
            ->toThrow(PurchaseOrderNotYetAccepted::class);
    }
});

it('refuses to record supplier communication once the order has concluded', function (): void {
    // `sent_at` is a single timestamp, so re-sending a finished order would
    // overwrite the record of when it was originally communicated.
    foreach ([PurchaseOrderStatus::Received, PurchaseOrderStatus::Closed, PurchaseOrderStatus::Cancelled] as $status) {
        $order = PurchaseOrder::factory()->create(['status' => $status]);

        expect(fn (): PurchaseOrder => $this->service->send($this->manager, $order))
            ->toThrow($status === PurchaseOrderStatus::Cancelled
                ? PurchaseOrderNotYetAccepted::class
                : PurchaseOrderAlreadyConcluded::class);
    }
});

it('keeps the original sent_at when a partially received order is sent again', function (): void {
    $order = PurchaseOrder::factory()->partiallyReceived()->create();
    $originalSentAt = $order->sent_at;

    Carbon::setTestNow(now()->addDay());

    try {
        $resent = $this->service->send($this->manager, $order);
    } finally {
        Carbon::setTestNow();
    }

    expect($originalSentAt)->not->toBeNull()
        ->and($resent->sent_at?->equalTo($originalSentAt))->toBeTrue();
});

it('short-closes a partially received order and keeps the reason', function (): void {
    $order = PurchaseOrder::factory()->partiallyReceived()->create();

    $closed = $this->service->close($this->manager, $order, 'Supplier discontinued the item');

    expect($closed->status)->toBe(PurchaseOrderStatus::Closed)
        ->and($closed->closure_reason)->toBe('Supplier discontinued the item')
        ->and($closed->closed_at)->not->toBeNull();
});

it('cancels an order that has no completed receipt', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();

    $cancelled = $this->service->cancel($this->manager, $order, 'Duplicate order');

    expect($cancelled->status)->toBe(PurchaseOrderStatus::Cancelled)
        ->and($cancelled->cancellation_reason)->toBe('Duplicate order');
});

it('voids the draft supplier bill of a cancelled order so no payable can be approved for it', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();
    $draft = Bill::factory()->forPurchaseOrder($order)->create(['status' => BillStatus::Draft->value]);
    $alreadyCancelled = Bill::factory()->forPurchaseOrder($order)->create(['status' => BillStatus::Cancelled->value]);

    $this->service->cancel($this->manager, $order, 'Duplicate order');

    expect($draft->refresh()->status)->toBe(BillStatus::Cancelled)
        ->and($alreadyCancelled->refresh()->status)->toBe(BillStatus::Cancelled)
        ->and(Activity::query()
            ->where('subject_type', $draft->getMorphClass())
            ->where('subject_id', $draft->getKey())
            ->where('description', 'accounting.bill.cancelled_with_purchase_order')
            ->exists())->toBeTrue();
});

it('refuses to cancel an order whose supplier bill is already approved', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();
    $approved = Bill::factory()->forPurchaseOrder($order)->create(['status' => BillStatus::Approved->value]);

    expect(fn (): PurchaseOrder => $this->service->cancel($this->manager, $order, 'Duplicate order'))
        ->toThrow(PurchaseOrderNotCancellable::class, 'approved supplier bill');

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Accepted)
        ->and($approved->refresh()->status)->toBe(BillStatus::Approved);
});

it('returns outstanding linked Sales demand to sourcing when a Purchase Order is cancelled', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();
    $variant = ProductVariant::factory()->create();
    $purchaseLine = $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => 4,
        'unit_cost' => '10.00',
    ]);
    $purchaseLine->forceFill([
        'base_quantity' => '4.000000',
        'received_base_quantity' => '1.000000',
    ])->save();

    $salesOrder = Order::factory()->create();
    $salesLine = OrderLine::factory()
        ->for($salesOrder)
        ->for($variant, 'productVariant')
        ->create([
            'quantity' => 4,
            'unit_id' => $variant->unit_id,
        ]);
    $linked = $salesOrder->procurementRequirements()->create([
        'order_line_id' => $salesLine->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_order_id' => $order->getKey(),
        'purchase_order_line_id' => $purchaseLine->getKey(),
        'required_base_quantity' => 4,
        'fulfilled_base_quantity' => 0,
        'status' => 'purchasing',
    ]);

    $cancelled = $this->service->cancel($this->manager, $order, 'Supplier cannot fulfill');

    $replacement = $salesOrder->procurementRequirements()
        ->where('status', 'open')
        ->whereNull('purchase_order_id')
        ->sole();

    expect($cancelled->status)->toBe(PurchaseOrderStatus::Cancelled)
        ->and($linked->refresh()->status)->toBe('superseded')
        ->and((float) $linked->fulfilled_base_quantity)->toBe(1.0)
        ->and((float) $replacement->required_base_quantity)->toBe(3.0);
});

it('refuses cancellation once a receipt has completed, directing the buyer to short-close (V-13)', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();
    $order->receipts()->create([
        'operation_type' => 'receipt',
        'destination_warehouse_id' => Warehouse::factory()->create()->getKey(),
        'supplier_id' => $order->supplier_id,
    ])->forceFill(['completed_at' => now(), 'stage' => 'done'])->save();

    expect(fn (): PurchaseOrder => $this->service->cancel($this->manager, $order->refresh(), 'Changed my mind'))
        ->toThrow(AuthorizationException::class);
});

it('refuses cancellation at the service layer too, with the policy neutralised (R-G)', function (): void {
    // The dual checkpoint, and why it is not redundant: the policy check and the
    // service check happen at different moments, so a receipt completing between
    // them would let a cancellation through if only the policy guarded it. Gate
    // is opened here to isolate the second guard, which is exactly the window a
    // concurrent receipt would open on its own.
    Gate::before(static fn (): bool => true);

    $order = PurchaseOrder::factory()->sent()->create();
    $order->receipts()->create([
        'operation_type' => 'receipt',
        'destination_warehouse_id' => Warehouse::factory()->create()->getKey(),
        'supplier_id' => $order->supplier_id,
    ])->forceFill(['completed_at' => now(), 'stage' => 'done'])->save();

    expect(fn (): PurchaseOrder => $this->service->cancel($this->manager, $order->refresh(), 'Changed my mind'))
        ->toThrow(PurchaseOrderNotCancellable::class, $order->purchase_order_number);
});

it('blocks short-close and cancellation at the service boundary while an Inventory receipt is open', function (): void {
    Gate::before(static fn (): bool => true);

    $warehouse = Warehouse::factory()->create();

    $closeOrder = PurchaseOrder::factory()->partiallyReceived()->create();
    $closeOrder->receipts()->create([
        'operation_type' => 'receipt',
        'destination_warehouse_id' => $warehouse->getKey(),
        'supplier_id' => $closeOrder->supplier_id,
    ])->forceFill(['stage' => 'ready'])->save();

    expect(fn (): PurchaseOrder => $this->service->close(
        $this->manager,
        $closeOrder->refresh(),
        'Supplier cannot complete the balance',
    ))->toThrow(PurchaseOrderNotCancellable::class, 'active Inventory receipt');

    $cancelOrder = PurchaseOrder::factory()->sent()->create();
    $cancelOrder->receipts()->create([
        'operation_type' => 'receipt',
        'destination_warehouse_id' => $warehouse->getKey(),
        'supplier_id' => $cancelOrder->supplier_id,
    ])->forceFill(['stage' => 'ready'])->save();

    expect(fn (): PurchaseOrder => $this->service->cancel(
        $this->manager,
        $cancelOrder->refresh(),
        'Duplicate commitment',
    ))->toThrow(PurchaseOrderNotCancellable::class, 'active Inventory receipt');
});

it('revalidates inactive product and supplier-reference facts at submit time', function (): void {
    PurchaseSetting::factory()->threshold('999999.00')->create();

    $inactiveProductOrder = orderWithLines('10.00');
    $inactiveProductOrder->lines()->firstOrFail()->productVariant()->update(['is_active' => false]);

    expect(fn (): PurchaseOrder => $this->service->submit($this->officer, $inactiveProductOrder->refresh()))
        ->toThrow(InvalidPurchaseOrderLine::class);

    $staleReferenceOrder = orderWithLines('10.00');
    $line = $staleReferenceOrder->lines()->firstOrFail();
    $reference = SupplierProductReference::query()
        ->where('supplier_id', $staleReferenceOrder->supplier_id)
        ->where('product_variant_id', $line->product_variant_id)
        ->sole();

    $line->forceFill(['supplier_product_reference_id' => $reference->getKey()])->save();
    $reference->update(['is_active' => false]);

    expect(fn (): PurchaseOrder => $this->service->submit($this->officer, $staleReferenceOrder->refresh()))
        ->toThrow(InvalidPurchaseOrderLine::class);
});

it('refuses every lifecycle action to a role that lacks its permission', function (): void {
    $order = PurchaseOrder::factory()->pendingApproval()->create();

    expect(fn (): PurchaseOrder => $this->service->approve($this->officer, $order))->toThrow(AuthorizationException::class)
        ->and(fn (): PurchaseOrder => $this->service->reject($this->officer, $order, 'no'))->toThrow(AuthorizationException::class);

    $approved = PurchaseOrder::factory()->accepted()->create();
    expect(fn (): PurchaseOrder => $this->service->send($this->officer, $approved))->toThrow(AuthorizationException::class);

    $sent = PurchaseOrder::factory()->sent()->create();
    expect(fn (): PurchaseOrder => $this->service->cancel($this->officer, $sent, 'no'))->toThrow(AuthorizationException::class);

    $partial = PurchaseOrder::factory()->partiallyReceived()->create();
    expect(fn (): PurchaseOrder => $this->service->close($this->officer, $partial, 'no'))->toThrow(AuthorizationException::class);
});

it('records an audit entry for every transition (FR-054)', function (): void {
    PurchaseSetting::factory()->threshold('10.00')->create();

    $submitted = $this->service->submit($this->officer, orderWithLines('500.00'));
    $approved = $this->service->approve($this->manager, $submitted);
    $this->service->send($this->manager, $approved);

    $events = AuditLog::query()
        ->where('subject_type', PurchaseOrder::class)
        ->where('subject_id', $submitted->getKey())
        ->pluck('description')
        ->all();

    expect($events)->toContain('purchasing.order.submitted')
        ->toContain('purchasing.order.approved')
        ->toContain('purchasing.order.sent');
});
