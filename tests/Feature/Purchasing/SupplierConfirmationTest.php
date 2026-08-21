<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Models\AuditLog;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchasing\Exceptions\InvalidConfirmationTarget;
use App\Services\Purchasing\SupplierConfirmationService;
use Carbon\CarbonImmutable;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();
    $this->service = app(SupplierConfirmationService::class);
    $this->officer = User::factory()->create();
    $this->officer->assignRole(DashboardRole::PurchasingOfficer->value);
    $this->actingAs($this->officer);
});

it('records a confirmation against a purchase order', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();

    $confirmation = $this->service->record($this->officer, $order, $order->supplier_id, 'Chased by phone');

    expect($confirmation->confirmable_type)->toBe(PurchaseOrder::class)
        ->and($confirmation->confirmable_id)->toBe($order->getKey())
        ->and($confirmation->confirmation_status)->toBe(SupplierConfirmationStatus::Pending)
        ->and($confirmation->notes)->toBe('Chased by phone')
        ->and($order->refresh()->confirmations)->toHaveCount(1);
});

it('records a confirmation against a customer order and marks it as waiting (FR-033)', function (): void {
    $customerOrder = Order::factory()->create();
    $supplier = Supplier::factory()->create();

    $this->service->record($this->officer, $customerOrder, $supplier->getKey(), 'Out of stock locally');

    $customerOrder->refresh();

    expect($customerOrder->status)->toBe('pending_supplier_confirmation')
        ->and($customerOrder->pending_reason)->toBe('Out of stock locally');
});

it('refuses any target other than a purchase order or a customer order (V-09, FR-028)', function (): void {
    // The morph column is a varchar and will take whatever it is given, so the
    // restriction has to live in the service.
    $warehouse = Warehouse::factory()->create();

    expect(fn (): SupplierConfirmation => $this->service->record($this->officer, $warehouse, Supplier::factory()->create()->getKey()))
        ->toThrow(InvalidConfirmationTarget::class);

    expect(fn (): SupplierConfirmation => $this->service->record($this->officer, CustomerProfile::factory()->create(), Supplier::factory()->create()->getKey()))
        ->toThrow(InvalidConfirmationTarget::class);
});

it('answers a pending confirmation once, recording who and when', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();
    $confirmation = $this->service->record($this->officer, $order, $order->supplier_id);

    $answered = $this->service->answer(
        $this->officer,
        $confirmation,
        SupplierConfirmationStatus::Confirmed,
        CarbonImmutable::parse($order->ordered_at)->addWeek(),
        'Promised for next Friday',
    );

    expect($answered->confirmation_status)->toBe(SupplierConfirmationStatus::Confirmed)
        ->and($answered->confirmed_by)->toBe($this->officer->getKey())
        ->and($answered->confirmed_at)->not->toBeNull()
        ->and($answered->promised_at?->toDateString())->toBe(CarbonImmutable::parse($order->ordered_at)->addWeek()->toDateString());
});

it('refuses to amend an answered confirmation, at both checkpoints (FR-031, R-E)', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();
    $confirmation = SupplierConfirmation::factory()->confirmed()->create([
        'confirmable_type' => PurchaseOrder::class,
        'confirmable_id' => $order->getKey(),
        'supplier_id' => $order->supplier_id,
    ]);

    // Policy checkpoint.
    expect($this->officer->can('answer', $confirmation))->toBeFalse();

    expect(fn (): SupplierConfirmation => $this->service->answer($this->officer, $confirmation, SupplierConfirmationStatus::Rejected))
        ->toThrow(AuthorizationException::class);
});

it('refuses a promised date earlier than the document was ordered (V-10, FR-030)', function (): void {
    $order = PurchaseOrder::factory()->sent()->create(['ordered_at' => today()]);
    $confirmation = $this->service->record($this->officer, $order, $order->supplier_id);

    expect(fn (): SupplierConfirmation => $this->service->answer(
        $this->officer,
        $confirmation,
        SupplierConfirmationStatus::Confirmed,
        CarbonImmutable::parse(today()->subDay()),
    ))->toThrow(InvalidConfirmationTarget::class);
});

it('keeps a chronological history, because a correction appends rather than overwrites', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();

    $first = $this->service->record($this->officer, $order, $order->supplier_id, 'Asked');
    $this->service->answer($this->officer, $first, SupplierConfirmationStatus::Rejected, null, 'Out of stock');

    $second = $this->service->record($this->officer, $order, $order->supplier_id, 'Asked again');
    $this->service->answer($this->officer, $second, SupplierConfirmationStatus::Confirmed, CarbonImmutable::parse($order->ordered_at)->addDays(3));

    $history = $order->refresh()->confirmations()->orderBy('id')->get();

    expect($history)->toHaveCount(2)
        ->and($history[0]->confirmation_status)->toBe(SupplierConfirmationStatus::Rejected)
        ->and($history[1]->confirmation_status)->toBe(SupplierConfirmationStatus::Confirmed);
});

it('flags a purchase order whose latest answer was a rejection without moving its status (FR-034)', function (): void {
    // A supplier declining is information the buyer acts on, not a lifecycle
    // transition — a supplier who says no by email and ships anyway is a real
    // thing that happens.
    $order = PurchaseOrder::factory()->sent()->create();
    $confirmation = $this->service->record($this->officer, $order, $order->supplier_id);

    $this->service->answer($this->officer, $confirmation, SupplierConfirmationStatus::Rejected, null, 'Discontinued');

    $order->refresh();

    expect($order->hasRejectedConfirmation())->toBeTrue()
        ->and($order->status)->toBe(PurchaseOrderStatus::Sent)
        ->and($order->status->isReceivable())->toBeTrue();
});

it('clears the flag once a later confirmation supersedes the rejection', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();

    $first = $this->service->record($this->officer, $order, $order->supplier_id);
    $this->service->answer($this->officer, $first, SupplierConfirmationStatus::Rejected);

    $second = $this->service->record($this->officer, $order, $order->supplier_id);
    $this->service->answer($this->officer, $second, SupplierConfirmationStatus::Confirmed, CarbonImmutable::parse($order->ordered_at));

    expect($order->refresh()->hasRejectedConfirmation())->toBeFalse();
});

it('moves a customer order to confirmed and clears its pending reason', function (): void {
    $customerOrder = Order::factory()->create();
    $supplier = Supplier::factory()->create();

    $confirmation = $this->service->record($this->officer, $customerOrder, $supplier->getKey(), 'Waiting on supplier');
    $this->service->answer($this->officer, $confirmation, SupplierConfirmationStatus::Confirmed, CarbonImmutable::now()->addWeek());

    $customerOrder->refresh();

    expect($customerOrder->status)->toBe('supplier_confirmed')
        // A confirmed order is no longer pending on anything, so a leftover
        // reason would read as an unresolved problem.
        ->and($customerOrder->pending_reason)->toBeNull();
});

it('moves a customer order to rejected and keeps the reason', function (): void {
    $customerOrder = Order::factory()->create();
    $supplier = Supplier::factory()->create();

    $confirmation = $this->service->record($this->officer, $customerOrder, $supplier->getKey());
    $this->service->answer($this->officer, $confirmation, SupplierConfirmationStatus::Rejected, null, 'Discontinued line');

    $customerOrder->refresh();

    expect($customerOrder->status)->toBe('supplier_rejected')
        ->and($customerOrder->pending_reason)->toBe('Discontinued line');
});

it('refuses recording to a role without the record permission', function (): void {
    $reviewer = User::factory()->create();
    $reviewer->assignRole(DashboardRole::Reviewer->value);

    $order = PurchaseOrder::factory()->sent()->create();

    expect(fn (): SupplierConfirmation => $this->service->record($reviewer, $order, $order->supplier_id))
        ->toThrow(AuthorizationException::class);
});

it('logs an audit entry when a confirmation is answered', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();
    $confirmation = $this->service->record($this->officer, $order, $order->supplier_id);

    $this->service->answer($this->officer, $confirmation, SupplierConfirmationStatus::Confirmed, CarbonImmutable::parse($order->ordered_at));

    expect(AuditLog::query()
        ->where('subject_type', SupplierConfirmation::class)
        ->where('subject_id', $confirmation->getKey())
        ->where('description', 'purchasing.confirmation.answered')
        ->exists())->toBeTrue();
});
