<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseOrderStatus;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Unit;
use App\Models\User;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotEditable;
use App\Services\Purchasing\PurchaseOrderService;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/*
 * SC-006: a sent purchase order cannot be changed by any path.
 *
 * Two checkpoints, tested separately. The policy refuses first, which is what
 * hides the buttons; the service refuses independently, which is what stops a
 * caller who never went through a page. Testing only the first would leave the
 * console, the queue, and any future API able to rewrite a commitment the
 * supplier has already received.
 */

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();
    $this->service = app(PurchaseOrderService::class);
    $this->manager = User::factory()->create();
    $this->manager->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($this->manager);
});

function frozenOrder(PurchaseOrderStatus $status): PurchaseOrder
{
    $order = PurchaseOrder::factory()->create(['status' => $status]);

    $order->lines()->create([
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity_ordered' => 5,
        'unit_cost' => '10.00',
        'line_total' => '50.00',
    ]);

    return $order->refresh();
}

it('treats only a draft as editable, so approval freezes the figures before transmission does', function (): void {
    foreach (PurchaseOrderStatus::cases() as $status) {
        $order = PurchaseOrder::factory()->create(['status' => $status]);

        expect($this->manager->can('update', $order))
            ->toBe($status === PurchaseOrderStatus::Draft, $status->value);
    }
});

it('refuses a header edit on a sent order at the policy checkpoint', function (): void {
    $order = frozenOrder(PurchaseOrderStatus::Sent);

    expect(fn (): PurchaseOrder => $this->service->updateDraft($this->manager, $order, ['notes' => 'renegotiated']))
        ->toThrow(AuthorizationException::class);
});

it('refuses a header edit on a sent order at the service checkpoint, with the policy neutralised', function (): void {
    // Gate opened deliberately: this asserts the guard that a caller who never
    // touched a Filament page still hits.
    Gate::before(static fn (): bool => true);

    $order = frozenOrder(PurchaseOrderStatus::Sent);

    expect(fn (): PurchaseOrder => $this->service->updateDraft($this->manager, $order, ['notes' => 'renegotiated']))
        ->toThrow(PurchaseOrderNotEditable::class, $order->purchase_order_number);
});

it('refuses adding, editing, and removing lines on a sent order at the service checkpoint', function (): void {
    Gate::before(static fn (): bool => true);

    $order = frozenOrder(PurchaseOrderStatus::Sent);
    /** @var PurchaseOrderLine $line */
    $line = $order->lines()->firstOrFail();

    expect(fn (): PurchaseOrderLine => $this->service->addLine($this->manager, $order, [
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity_ordered' => 1,
    ]))->toThrow(PurchaseOrderNotEditable::class);

    expect(fn (): PurchaseOrderLine => $this->service->updateLine($this->manager, $line, ['quantity_ordered' => 99]))
        ->toThrow(PurchaseOrderNotEditable::class);

    expect(fn () => $this->service->removeLine($this->manager, $line))
        ->toThrow(PurchaseOrderNotEditable::class);
});

it('leaves the sent order untouched after every refused attempt', function (): void {
    Gate::before(static fn (): bool => true);

    $order = frozenOrder(PurchaseOrderStatus::Sent);
    $before = $order->only(['supplier_id', 'destination_warehouse_id', 'currency_code', 'total_amount', 'notes']);
    $lineBefore = $order->lines()->firstOrFail()->only(['quantity_ordered', 'unit_cost', 'line_total']);

    // Each of these throws; the point is that none of them leaves a partial
    // write behind, because the service owns the transaction.
    foreach ([
        fn () => $this->service->updateDraft($this->manager, $order, ['notes' => 'x']),
        fn () => $this->service->updateLine($this->manager, $order->lines()->firstOrFail(), ['unit_cost' => 999]),
        fn () => $this->service->removeLine($this->manager, $order->lines()->firstOrFail()),
    ] as $attempt) {
        try {
            $attempt();
        } catch (PurchaseOrderNotEditable) {
            // expected
        }
    }

    expect($order->refresh()->only(array_keys($before)))->toBe($before)
        ->and($order->lines()->firstOrFail()->only(array_keys($lineBefore)))->toBe($lineBefore)
        ->and($order->lines()->count())->toBe(1);
});

it('freezes an approved order too, before it has even been sent', function (): void {
    // The figure that was approved is the figure that gets sent. Allowing edits
    // between approval and transmission would let an approved amount be raised
    // without a second approval.
    Gate::before(static fn (): bool => true);

    $order = frozenOrder(PurchaseOrderStatus::Approved);

    expect(fn (): PurchaseOrder => $this->service->updateDraft($this->manager, $order, ['notes' => 'x']))
        ->toThrow(PurchaseOrderNotEditable::class);
});

it('refuses deletion of anything that is not a draft', function (): void {
    foreach (PurchaseOrderStatus::cases() as $status) {
        $order = PurchaseOrder::factory()->create(['status' => $status]);

        expect($this->manager->can('delete', $order))
            ->toBe($status === PurchaseOrderStatus::Draft, $status->value);
    }
});
