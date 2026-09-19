<?php

declare(strict_types=1);

use App\Filament\Resources\DeliveryNotes\Pages\ViewDeliveryNote;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function coverageDeliveryReadyForInvoice(): InventoryOperation
{
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();
    $order = Order::factory()->create(['customer_id' => $customer->getKey()]);
    $orderLine = OrderLine::factory()->create([
        'order_id' => $order->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => 2,
        'unit_price' => 15,
        'tax_amount' => 0,
        'line_total' => 30,
    ]);

    $delivery = InventoryOperation::factory()->delivery()->done()->create([
        'source_document_type' => Order::class,
        'source_document_id' => $order->getKey(),
        'customer_id' => $customer->getKey(),
    ]);

    $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'order_line_id' => $orderLine->getKey(),
        'quantity' => 2,
        'unit_id' => $variant->unit_id,
    ]);

    return $delivery->refresh();
}

it('creates an invoice from a completed delivery through the delivery note action', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();
    $delivery = coverageDeliveryReadyForInvoice();

    Livewire::actingAs($actor)
        ->test(ViewDeliveryNote::class, ['record' => $delivery->getRouteKey()])
        ->assertActionVisible(TestAction::make('create_invoice'))
        ->callAction(TestAction::make('create_invoice'))
        ->assertHasNoActionErrors();

    $invoice = Invoice::query()->sole();

    expect($invoice->inventory_operation_id)->toBe($delivery->getKey())
        ->and($delivery->refresh()->isInvoiced())->toBeTrue();
});

it('hides create invoice for incomplete or already invoiced deliveries', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();

    $incomplete = InventoryOperation::factory()->delivery()->ready()->create();
    Livewire::actingAs($actor)
        ->test(ViewDeliveryNote::class, ['record' => $incomplete->getRouteKey()])
        ->assertActionHidden(TestAction::make('create_invoice'));

    $delivery = coverageDeliveryReadyForInvoice();
    app(InvoiceService::class)->createFromDelivery($actor, $delivery);

    Livewire::actingAs($actor)
        ->test(ViewDeliveryNote::class, ['record' => $delivery->getRouteKey()])
        ->assertActionHidden(TestAction::make('create_invoice'));
});
