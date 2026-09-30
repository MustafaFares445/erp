<?php

declare(strict_types=1);

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Models\Bill;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierConfirmation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

function coverageFollowUpOrder(User $actor): PurchaseOrder
{
    $order = PurchaseOrder::factory()->accepted($actor)->create([
        'sent_at' => now(),
        'supplier_confirmation_required' => true,
    ]);

    $variant = ProductVariant::factory()->create();
    $line = PurchaseOrderLine::factory()->create([
        'purchase_order_id' => $order->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => '2.000000',
    ]);
    $line->forceFill([
        'transaction_quantity' => '2.000000',
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '2.000000',
        'received_base_quantity' => '0.000000',
    ])->save();

    return $order;
}

it('covers pending supplier confirmation action URL', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $order = PurchaseOrder::factory()->accepted($actor)->create(['sent_at' => now()]);
    $confirmation = SupplierConfirmation::factory()->create([
        'purchase_order_id' => $order->getKey(),
        'supplier_id' => $order->supplier_id,
    ]);

    $page = new ReflectionClass(ViewPurchaseOrder::class)->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ViewPurchaseOrder::class, 'recordSupplierResponseAction');
    $response = $method->invoke($page)->record($order);

    expect($response->isVisible())->toBeTrue()
        ->and($response->getUrl())->toContain((string) $confirmation->getKey());
});

it('covers supplier follow-up unauthenticated guard and success action', function (): void {
    $actor = User::factory()->admin()->create();
    $order = coverageFollowUpOrder($actor);

    $page = new ReflectionClass(ViewPurchaseOrder::class)->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ViewPurchaseOrder::class, 'requestSupplierFollowUpAction');
    $action = $method->invoke($page);

    auth()->logout();
    ($action->getActionFunction())($order);

    expect($order->confirmations()->count())->toBe(0);

    $this->actingAs($actor);
    ($action->getActionFunction())($order->refresh());

    expect($order->confirmations()->count())->toBe(1)
        ->and($order->confirmations()->latest('id')->first())->toBeInstanceOf(SupplierConfirmation::class);
});

it('covers review supplier bill action with and without an accounting bill', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $order = PurchaseOrder::factory()->create([
        'status' => PurchaseOrderStatus::Received->value,
        'sent_at' => now(),
    ]);

    $page = new ReflectionClass(ViewPurchaseOrder::class)->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ViewPurchaseOrder::class, 'reviewBillAction');

    $withoutBill = $method->invoke($page)->record($order);

    expect($withoutBill->getLabel())->toBe('Create supplier bill')
        ->and($withoutBill->isVisible())->toBeTrue()
        ->and($withoutBill->getUrl())->toContain('action=create');

    $bill = Bill::factory()->forPurchaseOrder($order)->create([
        'status' => 'draft',
    ]);

    $order->unsetRelation('bills');

    $withBill = $method->invoke($page)->record($order->refresh());

    expect($withBill->getLabel())->toBe('Review supplier bill')
        ->and($withBill->isVisible())->toBeTrue()
        ->and($withBill->getUrl())->toContain((string) $bill->getKey());
});
