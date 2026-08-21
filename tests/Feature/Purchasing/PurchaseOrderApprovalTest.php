<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseOrderStatus;
use App\Models\AuditLog;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseSetting;
use App\Models\Unit;
use App\Models\User;
use App\Services\Purchasing\Exceptions\InvalidPurchaseOrderLine;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotCancellable;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotEditable;
use App\Services\Purchasing\Exceptions\SelfApprovalRejected;
use App\Services\Purchasing\PurchaseOrderApprovalService;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
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

    $order->lines()->create([
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity_ordered' => 1,
        'unit_cost' => $total,
        'line_total' => $total,
    ]);

    return $order->refresh();
}

it('auto-approves a submission at or below the threshold and attributes it to the submitter (FR-020, R-004)', function (): void {
    PurchaseSetting::factory()->threshold('500.00')->create();

    $order = orderWithLines('500.00');

    $submitted = $this->service->submit($this->officer, $order);

    expect($submitted->status)->toBe(PurchaseOrderStatus::Approved)
        ->and($submitted->submitted_by)->toBe($this->officer->getKey())
        // "Nobody approved it" is not a truthful record of who caused the state
        // change, so an auto-approval attributes to the submitter (SC-005).
        ->and($submitted->approved_by)->toBe($this->officer->getKey())
        ->and($submitted->approved_at)->not->toBeNull();
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

    expect($approved->status)->toBe(PurchaseOrderStatus::Approved)
        ->and($approved->approved_by)->toBe($admin->getKey());
});

it('lets a different approver approve what an officer submitted', function (): void {
    PurchaseSetting::factory()->threshold('10.00')->create();

    $submitted = $this->service->submit($this->officer, orderWithLines('500.00'));
    $approved = $this->service->approve($this->manager, $submitted);

    expect($approved->status)->toBe(PurchaseOrderStatus::Approved)
        ->and($approved->approved_by)->toBe($this->manager->getKey())
        ->and($approved->submitted_by)->toBe($this->officer->getKey());
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

it('sends an approved order and stamps the immutability boundary', function (): void {
    $order = PurchaseOrder::factory()->approved()->create();

    $sent = $this->service->send($this->manager, $order);

    expect($sent->status)->toBe(PurchaseOrderStatus::Sent)
        ->and($sent->sent_at)->not->toBeNull();
});

it('refuses to send anything that is not approved', function (): void {
    foreach ([PurchaseOrderStatus::Draft, PurchaseOrderStatus::PendingApproval, PurchaseOrderStatus::Sent] as $status) {
        $order = PurchaseOrder::factory()->create(['status' => $status]);

        expect(fn (): PurchaseOrder => $this->service->send($this->manager, $order))
            ->toThrow(PurchaseOrderNotEditable::class);
    }
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

it('refuses cancellation once a receipt has completed, directing the buyer to short-close (V-13)', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();
    $order->receipts()->create([
        'operation_type' => 'receipt',
        'destination_warehouse_id' => $order->destination_warehouse_id,
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
        'destination_warehouse_id' => $order->destination_warehouse_id,
        'supplier_id' => $order->supplier_id,
    ])->forceFill(['completed_at' => now(), 'stage' => 'done'])->save();

    expect(fn (): PurchaseOrder => $this->service->cancel($this->manager, $order->refresh(), 'Changed my mind'))
        ->toThrow(PurchaseOrderNotCancellable::class, $order->purchase_order_number);
});

it('refuses every lifecycle action to a role that lacks its permission', function (): void {
    $order = PurchaseOrder::factory()->pendingApproval()->create();

    expect(fn (): PurchaseOrder => $this->service->approve($this->officer, $order))->toThrow(AuthorizationException::class)
        ->and(fn (): PurchaseOrder => $this->service->reject($this->officer, $order, 'no'))->toThrow(AuthorizationException::class);

    $approved = PurchaseOrder::factory()->approved()->create();
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
