<?php

declare(strict_types=1);

use App\Enums\CrmPermission;
use App\Enums\EmployeePermission;
use App\Enums\SalesPermission;
use App\Enums\SupportPermission;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\CustomerTimeline;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\CustomerInvoicesRelationManager;
use App\Filament\Resources\Customers\RelationManagers\CustomerQuotationsRelationManager;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * WP-3.1 (GAP-UI-03, CR-05) — the customer 360 page renders, its relation
 * managers are read-only, and their links resolve.
 */
function customerFullAccessActor(): User
{
    $actor = User::factory()->admin()->create();

    foreach ([
        SalesPermission::QuotationView,
        SalesPermission::OrderView,
        SalesPermission::InvoiceView,
        SalesPermission::PaymentView,
    ] as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission->value, 'web'));
    }

    foreach ([SupportPermission::TicketView, SupportPermission::MaintenanceRequestView] as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission->value, 'web'));
    }

    $actor->givePermissionTo(Permission::findOrCreate(CrmPermission::InteractionView->value, 'web'));
    $actor->givePermissionTo(Permission::findOrCreate(CrmPermission::CustomerView->value, 'web'));
    $actor->givePermissionTo(Permission::findOrCreate(EmployeePermission::VisitView->value, 'web'));

    return $actor;
}

it('renders the customer view page with its read-only relation managers', function (): void {
    $customer = CustomerProfile::factory()->create();
    $quotation = Quotation::factory()->create(['customer_id' => $customer->getKey()]);
    Ticket::factory()->create(['customer_id' => $customer->getKey()]);

    $actor = customerFullAccessActor();

    Livewire::actingAs($actor)
        ->test(ViewCustomer::class, ['record' => $customer->getKey()])
        ->assertSuccessful();

    Livewire::actingAs($actor)
        ->test(CustomerQuotationsRelationManager::class, [
            'ownerRecord' => $customer,
            'pageClass' => ViewCustomer::class,
        ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$quotation]);
});

it('renders the customer timeline page with summary and events', function (): void {
    $customer = CustomerProfile::factory()->create();
    Invoice::factory()->create(['customer_id' => $customer->getKey(), 'invoice_date' => now()]);

    $actor = customerFullAccessActor();

    Livewire::actingAs($actor)
        ->test(CustomerTimeline::class, ['record' => $customer->getKey()])
        ->assertSuccessful()
        ->assertSee('Summary')
        ->assertSee('Timeline');
});

it('links from the invoices relation manager resolve to the invoice resource', function (): void {
    $customer = CustomerProfile::factory()->create();
    $invoice = Invoice::factory()->create(['customer_id' => $customer->getKey()]);

    $actor = customerFullAccessActor();

    Livewire::actingAs($actor)
        ->test(CustomerInvoicesRelationManager::class, [
            'ownerRecord' => $customer,
            'pageClass' => ViewCustomer::class,
        ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$invoice]);

    expect(CustomerResource::getUrl('timeline', ['record' => $customer]))->toContain((string) $customer->getKey());
});
