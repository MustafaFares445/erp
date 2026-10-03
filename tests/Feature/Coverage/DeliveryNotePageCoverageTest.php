<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\DeliveryNotes\Pages\ListDeliveryNotes;
use App\Filament\Resources\DeliveryNotes\Pages\ViewDeliveryNote;
use App\Jobs\GeneratePackingListDocument;
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
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

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

it('offers Generate Packing List from the delivery note view page too', function (): void {
    Gate::before(static fn (): bool => true);
    Queue::fake();
    $actor = User::factory()->create();
    $delivery = coverageDeliveryReadyForInvoice();

    Livewire::actingAs($actor)
        ->test(ViewDeliveryNote::class, ['record' => $delivery->getRouteKey()])
        ->callAction(TestAction::make('generate_packing_list'))
        ->assertHasNoActionErrors();

    Queue::assertPushed(GeneratePackingListDocument::class);
});

it('creates the invoice exactly once from the delivery note list row', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();
    $delivery = coverageDeliveryReadyForInvoice();

    Livewire::actingAs($actor)
        ->test(ListDeliveryNotes::class)
        ->assertTableActionVisible('create_invoice', $delivery)
        ->callTableAction('create_invoice', $delivery)
        ->assertHasNoTableActionErrors();

    expect(Invoice::query()->count())->toBe(1)
        ->and(Invoice::query()->sole()->inventory_operation_id)->toBe($delivery->getKey())
        ->and($delivery->refresh()->isInvoiced())->toBeTrue();

    Livewire::actingAs($actor)
        ->test(ListDeliveryNotes::class)
        ->assertTableActionHidden('create_invoice', $delivery)
        ->assertTableActionVisible('view', $delivery);

    expect(Invoice::query()->count())->toBe(1);
});

it('hides the row invoice action for undone deliveries and users who cannot create invoices', function (): void {
    $ready = InventoryOperation::factory()->delivery()->ready()->create();
    $done = coverageDeliveryReadyForInvoice();

    $viewer = User::factory()->employee()->create();
    $viewer->givePermissionTo(Permission::findOrCreate(InventoryPermission::DeliveryView->value, 'web'));

    Livewire::actingAs($viewer)
        ->test(ListDeliveryNotes::class)
        ->assertTableActionHidden('create_invoice', $done)
        ->assertTableActionHidden('create_invoice', $ready);

    Gate::before(static fn (): bool => true);

    Livewire::actingAs(User::factory()->create())
        ->test(ListDeliveryNotes::class)
        ->assertTableActionVisible('create_invoice', $done)
        ->assertTableActionHidden('create_invoice', $ready);
});
