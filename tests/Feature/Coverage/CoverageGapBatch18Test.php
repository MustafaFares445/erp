<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\InventoryReportType;
use App\Enums\InvoiceStatus;
use App\Enums\SerializedInventoryUnitStatus;
use App\Filament\Resources\InventoryReports\Pages\ManageInventoryReports;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Jobs\SendInvoiceEmail;
use App\Models\CustomerProfile;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryReportService;
use App\Services\Logistics\OutboundAvailabilityService;
use App\Services\Logistics\OutboundDispatchService;
use App\Services\Logistics\OutboundFulfillmentService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Sales\InvoiceBalanceService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

it('exports the current inventory catalog report through the real streamed callback', function (): void {
    (new InventoryPermissionSeeder)->run();

    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo([
        InventoryPermission::ReportView->value,
        InventoryPermission::CatalogView->value,
        InventoryPermission::Export->value,
    ]);

    ProductVariant::factory()->create();

    $page = Livewire::actingAs($viewer)
        ->test(ManageInventoryReports::class)
        ->set('activeTab', InventoryReportType::Catalog->value)
        ->instance();

    $response = $page->exportCurrentReport();

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    expect($csv)->not->toBe('')
        ->and(app(InventoryReportService::class)->availableReports($viewer))
        ->toContain(InventoryReportType::Catalog);
});

it('covers lead required-string success and unauthenticated actor guard', function (): void {
    $required = new ReflectionMethod(CreateLead::class, 'requiredString');
    expect($required->invoke(null, ['source' => 'manual'], 'source'))->toBe('manual');

    $actor = new ReflectionMethod(CreateLead::class, 'actor');
    auth()->logout();

    expect(fn (): mixed => $actor->invoke(null))
        ->toThrow(LogicException::class, 'authenticated CRM user');
});

it('uses the outbound dispatch planned-to-transit fallback when completion listeners are suppressed', function (): void {
    $actor = User::factory()->admin()->create();
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 1,
        'unit_id' => $variant->unit_id,
    ]);
    $warehouse = Warehouse::factory()->create();

    InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse, 'warehouse')->create([
        'on_hand_quantity' => 1,
        'reserved_quantity' => 0,
        'available_quantity' => 1,
    ]);
    SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create([
        'warehouse_id' => $warehouse->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
    ]);

    $availability = app(OutboundAvailabilityService::class)->suggest($order);
    $planned = app(OutboundFulfillmentService::class)->plan($actor, $order, $availability);
    $delivery = $planned->deliveries->firstOrFail();
    app(OutboundFulfillmentService::class)->prepare($actor, $delivery);

    Event::fake();

    $shipment = app(OutboundDispatchService::class)->dispatch($actor, $delivery);

    expect($shipment->refresh()->status->value)->toBe('in_transit');
});

it('rejects invoice email delivery when the customer email is invalid', function (): void {
    Storage::fake('local');

    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create(['email' => 'not-an-email']);
    $invoice = Invoice::factory()->for($customer, 'customer')->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
    ]);

    Storage::disk('local')->put('invalid-email-invoice.pdf', "%PDF-1.4\ncoverage\n%%EOF");
    $invoice->addMediaFromDisk('invalid-email-invoice.pdf', 'local')
        ->usingFileName('invalid-email-invoice.pdf')
        ->toMediaCollection('invoice-pdf', 'local');

    $job = new SendInvoiceEmail($invoice->getKey(), $actor->getKey());

    expect(fn (): mixed => $job->handle(
        app(InvoiceBalanceService::class),
        app(NotificationDispatcher::class),
    ))->toThrow(DomainException::class, 'valid email address');
});
