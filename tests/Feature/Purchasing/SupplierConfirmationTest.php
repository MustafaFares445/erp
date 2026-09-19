<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Models\AuditLog;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SupplierConfirmation;
use App\Models\User;
use App\Services\Purchasing\Exceptions\ConfirmationNotAmendable;
use App\Services\Purchasing\Exceptions\InvalidConfirmationTarget;
use App\Services\Purchasing\SupplierConfirmationService;
use Carbon\CarbonImmutable;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();
    $this->service = app(SupplierConfirmationService::class);
    $this->officer = User::factory()->create();
    $this->officer->assignRole(DashboardRole::PurchasingOfficer->value);
    $this->actingAs($this->officer);
});

/**
 * Builds a sent purchase order with one outstanding line, from a supplier
 * that requires confirmation (otherwise the demand is treated as already
 * confirmed and there is nothing left to ask about).
 */
function confirmableOrder(float $quantity = 5): PurchaseOrder
{
    $order = PurchaseOrder::factory()->sent()->create();
    $order->supplier()->update(['requires_confirmation' => true]);

    $variant = ProductVariant::factory()->create();
    $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => $quantity,
        'unit_cost' => '10.00',
    ]);

    return $order->refresh();
}

it('records a confirmation against every outstanding line of a purchase order', function (): void {
    $order = confirmableOrder();

    $confirmation = $this->service->recordPurchaseOrder($this->officer, $order, 'Chased by phone');

    expect($confirmation->purchase_order_id)->toBe($order->getKey())
        ->and($confirmation->supplier_id)->toBe($order->supplier_id)
        ->and($confirmation->confirmation_status)->toBe(SupplierConfirmationStatus::Pending)
        ->and($confirmation->notes)->toBe('Chased by phone')
        ->and($confirmation->items)->toHaveCount(1)
        ->and($order->refresh()->confirmations)->toHaveCount(1);
});

it('refuses recording when the order has nothing outstanding to confirm', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();
    $order->supplier()->update(['requires_confirmation' => true]);

    expect(fn (): SupplierConfirmation => $this->service->recordPurchaseOrder($this->officer, $order))
        ->toThrow(ValidationException::class);
});

it('answers a pending confirmation once, recording who and when', function (): void {
    $order = confirmableOrder(5);
    $confirmation = $this->service->recordPurchaseOrder($this->officer, $order);
    $item = $confirmation->items->sole();

    $answered = $this->service->respond(
        $this->officer,
        $confirmation,
        SupplierConfirmationStatus::Confirmed,
        CarbonImmutable::parse($order->ordered_at)->addWeek(),
        'Promised for next Friday',
        [['id' => $item->getKey(), 'confirmed_base_quantity' => 5, 'backordered_base_quantity' => 0]],
    );

    expect($answered->confirmation_status)->toBe(SupplierConfirmationStatus::Confirmed)
        ->and($answered->confirmed_by)->toBe($this->officer->getKey())
        ->and($answered->confirmed_at)->not->toBeNull()
        ->and($answered->promised_at?->toDateString())->toBe(CarbonImmutable::parse($order->ordered_at)->addWeek()->toDateString());
});

it('refuses to amend an answered confirmation, at both checkpoints (FR-031, R-E)', function (): void {
    $order = confirmableOrder(5);
    $confirmation = $this->service->recordPurchaseOrder($this->officer, $order);
    $item = $confirmation->items->sole();

    $this->service->respond(
        $this->officer,
        $confirmation,
        SupplierConfirmationStatus::Confirmed,
        CarbonImmutable::parse($order->ordered_at)->addWeek(),
        'Promised',
        [['id' => $item->getKey(), 'confirmed_base_quantity' => 5, 'backordered_base_quantity' => 0]],
    );
    $confirmation->refresh();

    // Policy checkpoint.
    expect($this->officer->can('answer', $confirmation))->toBeFalse();

    // Service checkpoint (Gate::authorize() inside respond()).
    expect(fn (): SupplierConfirmation => $this->service->respond(
        $this->officer,
        $confirmation,
        SupplierConfirmationStatus::Rejected,
        null,
        'Too late',
    ))->toThrow(AuthorizationException::class);
});

it('refuses a promised date earlier than the document was ordered (V-10, FR-030)', function (): void {
    $order = confirmableOrder(5);
    $order->forceFill(['ordered_at' => today()])->save();
    $confirmation = $this->service->recordPurchaseOrder($this->officer, $order);
    $item = $confirmation->items->sole();

    expect(fn (): SupplierConfirmation => $this->service->respond(
        $this->officer,
        $confirmation,
        SupplierConfirmationStatus::Confirmed,
        CarbonImmutable::parse(today()->subDay()),
        'Too early',
        [['id' => $item->getKey(), 'confirmed_base_quantity' => 5, 'backordered_base_quantity' => 0]],
    ))->toThrow(InvalidConfirmationTarget::class);
});

it('keeps a chronological history, appending a corrected confirmation for what remains outstanding', function (): void {
    $order = confirmableOrder(10);
    $first = $this->service->recordPurchaseOrder($this->officer, $order, 'Asked');
    $firstItem = $first->items->sole();

    $this->service->respond(
        $this->officer,
        $first,
        SupplierConfirmationStatus::Partial,
        CarbonImmutable::parse($order->ordered_at)->addWeek(),
        'Only some in stock',
        [['id' => $firstItem->getKey(), 'confirmed_base_quantity' => 4, 'backordered_base_quantity' => 6]],
    );

    // The 6 units the supplier backordered are still outstanding, so a second
    // confirmation can be raised for exactly that remainder.
    $second = $this->service->recordPurchaseOrder($this->officer, $order, 'Chased the backorder');
    $secondItem = $second->items->sole();

    $this->service->respond(
        $this->officer,
        $second,
        SupplierConfirmationStatus::Confirmed,
        CarbonImmutable::parse($order->ordered_at)->addWeeks(2),
        'Rest is in now',
        [['id' => $secondItem->getKey(), 'confirmed_base_quantity' => 6, 'backordered_base_quantity' => 0]],
    );

    $history = $order->refresh()->confirmations()->orderBy('id')->get();

    expect($history)->toHaveCount(2)
        ->and($history[0]->confirmation_status)->toBe(SupplierConfirmationStatus::Partial)
        ->and($history[1]->confirmation_status)->toBe(SupplierConfirmationStatus::Confirmed);
});

it('flags a purchase order whose latest answer was a rejection without moving its status (FR-034)', function (): void {
    // A supplier declining is information the buyer acts on, not a lifecycle
    // transition — a supplier who says no by email and ships anyway is a real
    // thing that happens.
    $order = confirmableOrder(5);
    $confirmation = $this->service->recordPurchaseOrder($this->officer, $order);

    $this->service->respond($this->officer, $confirmation, SupplierConfirmationStatus::Rejected, null, 'Discontinued');

    $order->refresh();

    expect($order->hasRejectedConfirmation())->toBeTrue()
        ->and($order->status)->toBe(PurchaseOrderStatus::Accepted)
        ->and($order->status->isReceivable())->toBeTrue();
});

it('clears the flag once a later confirmation for a different line supersedes the rejection', function (): void {
    $order = confirmableOrder(5);
    $rejected = $this->service->recordPurchaseOrder($this->officer, $order);
    $this->service->respond($this->officer, $rejected, SupplierConfirmationStatus::Rejected, null, 'Out of stock');

    expect($order->refresh()->hasRejectedConfirmation())->toBeTrue();

    // A second line, added afterwards, still has outstanding demand and can be
    // confirmed on its own — the most recent word from this supplier.
    $variant = ProductVariant::factory()->create();
    $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => 3,
        'unit_cost' => '10.00',
    ]);

    $second = $this->service->recordPurchaseOrder($this->officer, $order, 'Second line');
    $secondItem = $second->items->sole();

    $this->service->respond(
        $this->officer,
        $second,
        SupplierConfirmationStatus::Confirmed,
        CarbonImmutable::parse($order->ordered_at)->addWeek(),
        'Confirmed',
        [['id' => $secondItem->getKey(), 'confirmed_base_quantity' => 3, 'backordered_base_quantity' => 0]],
    );

    expect($order->refresh()->hasRejectedConfirmation())->toBeFalse();
});

it('refuses recording to a role without the record permission', function (): void {
    $reviewer = User::factory()->create();
    $reviewer->assignRole(DashboardRole::Reviewer->value);

    $order = confirmableOrder();

    expect(fn (): SupplierConfirmation => $this->service->recordPurchaseOrder($reviewer, $order))
        ->toThrow(AuthorizationException::class);
});

it('logs an audit entry when a confirmation is answered', function (): void {
    $order = confirmableOrder(5);
    $confirmation = $this->service->recordPurchaseOrder($this->officer, $order);
    $item = $confirmation->items->sole();

    $this->service->respond(
        $this->officer,
        $confirmation,
        SupplierConfirmationStatus::Confirmed,
        CarbonImmutable::parse($order->ordered_at)->addWeek(),
        'Promised',
        [['id' => $item->getKey(), 'confirmed_base_quantity' => 5, 'backordered_base_quantity' => 0]],
    );

    expect(AuditLog::query()
        ->where('subject_type', SupplierConfirmation::class)
        ->where('subject_id', $confirmation->getKey())
        ->where('description', 'purchasing.confirmation.answered')
        ->exists())->toBeTrue();
});

it('covers supplier confirmation response validation branches', function (): void {
    $order = confirmableOrder(5);
    $confirmation = $this->service->recordPurchaseOrder($this->officer, $order);
    $item = $confirmation->items->sole();
    $promise = CarbonImmutable::parse($order->ordered_at)->addWeek();

    expect(fn (): SupplierConfirmation => $this->service->respond(
        $this->officer,
        $confirmation->refresh(),
        SupplierConfirmationStatus::Confirmed,
        null,
        'Missing promise',
        [['id' => $item->getKey(), 'confirmed_base_quantity' => 5, 'backordered_base_quantity' => 0]],
    ))->toThrow(ValidationException::class);

    expect(fn (): SupplierConfirmation => $this->service->respond(
        $this->officer,
        $confirmation->refresh(),
        SupplierConfirmationStatus::Confirmed,
        $promise,
        'Missing line input',
        [],
    ))->toThrow(ValidationException::class);

    expect(fn (): SupplierConfirmation => $this->service->respond(
        $this->officer,
        $confirmation->refresh(),
        SupplierConfirmationStatus::Confirmed,
        $promise,
        'Confirmed cannot backorder',
        [['id' => $item->getKey(), 'confirmed_base_quantity' => 4, 'backordered_base_quantity' => 1]],
    ))->toThrow(ValidationException::class);

    expect(fn (): SupplierConfirmation => $this->service->respond(
        $this->officer,
        $confirmation->refresh(),
        SupplierConfirmationStatus::Partial,
        $promise,
        'Partial needs a backorder',
        [['id' => $item->getKey(), 'confirmed_base_quantity' => 5, 'backordered_base_quantity' => 0]],
    ))->toThrow(ValidationException::class);
});

it('covers supplier confirmation quantity commitment guards', function (): void {
    $order = confirmableOrder(5);
    $confirmation = $this->service->recordPurchaseOrder($this->officer, $order);
    $item = $confirmation->items->sole();
    $validated = new ReflectionMethod(SupplierConfirmationService::class, 'validatedCommitmentQuantities');

    expect(fn (): mixed => $validated->invoke($this->service, $item, [
        'confirmed_base_quantity' => 6,
        'backordered_base_quantity' => 0,
    ]))->toThrow(ValidationException::class)
        ->and(fn (): mixed => $validated->invoke($this->service, $item, [
            'confirmed_base_quantity' => 2,
            'backordered_base_quantity' => 2,
        ]))->toThrow(ValidationException::class);
});

it('rejects duplicate pending supplier confirmation evidence', function (): void {
    $order = confirmableOrder(5);
    $this->service->recordPurchaseOrder($this->officer, $order, 'First pending request');

    expect(fn (): SupplierConfirmation => $this->service->recordPurchaseOrder(
        $this->officer,
        $order,
        'Duplicate pending request',
    ))->toThrow(ValidationException::class);
});

it('rejects answering a supplier confirmation with no items', function (): void {
    $order = confirmableOrder(5);
    $confirmation = SupplierConfirmation::factory()->create([
        'purchase_order_id' => $order->getKey(),
        'supplier_id' => $order->supplier_id,
        'confirmation_status' => SupplierConfirmationStatus::Pending,
    ]);

    expect(fn (): SupplierConfirmation => $this->service->respond(
        $this->officer,
        $confirmation,
        SupplierConfirmationStatus::Rejected,
        null,
        'Reject without lines',
    ))->toThrow(ValidationException::class);
});

it('covers service-level supplier confirmation re-answer and blank-note guards', function (): void {
    Gate::before(static fn (): bool => true);

    $order = confirmableOrder(5);
    $confirmation = $this->service->recordPurchaseOrder($this->officer, $order);
    $item = $confirmation->items->sole();
    $promise = CarbonImmutable::parse($order->ordered_at)->addWeek();

    $this->service->respond(
        $this->officer,
        $confirmation,
        SupplierConfirmationStatus::Confirmed,
        $promise,
        'Initial answer',
        [['id' => $item->getKey(), 'confirmed_base_quantity' => 5, 'backordered_base_quantity' => 0]],
    );

    expect(fn (): SupplierConfirmation => $this->service->respond(
        $this->officer,
        $confirmation->refresh(),
        SupplierConfirmationStatus::Rejected,
        null,
        'Second answer',
    ))->toThrow(ConfirmationNotAmendable::class);

    $order2 = confirmableOrder(5);
    $pending = $this->service->recordPurchaseOrder($this->officer, $order2);
    $pendingItem = $pending->items->sole();

    expect(fn (): SupplierConfirmation => $this->service->respond(
        $this->officer,
        $pending,
        SupplierConfirmationStatus::Confirmed,
        CarbonImmutable::parse($order2->ordered_at)->addWeek(),
        '   ',
        [['id' => $pendingItem->getKey(), 'confirmed_base_quantity' => 5, 'backordered_base_quantity' => 0]],
    ))->toThrow(ValidationException::class);
});
