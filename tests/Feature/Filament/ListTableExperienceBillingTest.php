<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\InvoiceStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\WarrantyStatus;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\MaintenanceRequests\Pages\ListMaintenanceRequests;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\MaintenanceRecord;
use App\Models\User;
use Database\Seeders\SalesPermissionSeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function billingListSalesUser(): User
{
    (new SalesPermissionSeeder)->run();

    $user = User::factory()->admin()->create();
    $user->assignRole(DashboardRole::SalesOfficer->value);

    return $user;
}

function billingListSupportUser(): User
{
    (new SupportPermissionSeeder)->run();

    $user = User::factory()->admin()->create();
    $user->assignRole('Support Manager');

    return $user;
}

it('renders the invoice list with the Starred tab and keeps its existing presets', function (): void {
    Livewire::actingAs(billingListSalesUser())
        ->test(ListInvoices::class)
        ->assertSuccessful()
        ->assertSee(__('Starred'))
        ->assertSee(__('Needs attention'))
        ->assertSeeHtml("selectTableView('preset', 'starred')")
        ->assertSeeHtml("selectTableView('preset', 'overdue')");
});

it('stars invoices and lists them under the Starred tab', function (): void {
    $user = billingListSalesUser();
    [$starred, $other] = Invoice::factory()->count(2)->create()->all();

    Livewire::actingAs($user)
        ->test(ListInvoices::class)
        ->callTableColumnAction('is_favorited', $starred)
        ->call('selectTableView', 'preset', 'starred')
        ->assertSet('activeTab', 'starred')
        ->assertCanSeeTableRecords([$starred])
        ->assertCanNotSeeTableRecords([$other]);

    expect($starred->isFavoritedBy($user))->toBeTrue()
        ->and($other->isFavoritedBy($user))->toBeFalse();
});

it('filters invoices with query-builder rules', function (): void {
    $matching = CustomerProfile::factory()->create();
    $draft = Invoice::factory()->for($matching, 'customer')->create(['status' => InvoiceStatus::Draft]);
    $sent = Invoice::factory()->create(['status' => InvoiceStatus::Sent]);

    Livewire::actingAs(billingListSalesUser())
        ->test(ListInvoices::class)
        ->filterTable('queryBuilder', [
            'rules' => [
                'status-rule' => [
                    'type' => 'status',
                    'data' => ['operator' => 'is', 'settings' => ['values' => [InvoiceStatus::Sent->value]]],
                ],
            ],
        ])
        ->assertCanSeeTableRecords([$sent])
        ->assertCanNotSeeTableRecords([$draft]);

    Livewire::actingAs(billingListSalesUser())
        ->test(ListInvoices::class)
        ->filterTable('queryBuilder', [
            'rules' => [
                'customer-rule' => [
                    'type' => 'customer',
                    'data' => ['operator' => 'isRelatedTo', 'settings' => ['value' => [$matching->getKey()]]],
                ],
            ],
        ])
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$sent]);
});

it('filters invoices by amount and due date rules', function (): void {
    $small = Invoice::factory()->create(['total_amount' => 100, 'due_date' => today()->addDays(30)]);
    $large = Invoice::factory()->create(['total_amount' => 5000, 'due_date' => today()->subDays(3)]);

    Livewire::actingAs(billingListSalesUser())
        ->test(ListInvoices::class)
        ->filterTable('queryBuilder', [
            'rules' => [
                'amount-rule' => [
                    'type' => 'total_amount',
                    'data' => ['operator' => 'isMin', 'settings' => ['number' => '1000']],
                ],
            ],
        ])
        ->assertCanSeeTableRecords([$large])
        ->assertCanNotSeeTableRecords([$small]);

    Livewire::actingAs(billingListSalesUser())
        ->test(ListInvoices::class)
        ->filterTable('queryBuilder', [
            'rules' => [
                'due-rule' => [
                    'type' => 'due_date',
                    'data' => ['operator' => 'isAfter', 'settings' => ['mode' => 'absolute', 'date' => today()->toDateString()]],
                ],
            ],
        ])
        ->assertCanSeeTableRecords([$small])
        ->assertCanNotSeeTableRecords([$large]);
});

it('groups the invoice list without error', function (string $group): void {
    $invoices = Invoice::factory()->count(2)->create();

    Livewire::actingAs(billingListSalesUser())
        ->test(ListInvoices::class)
        ->set('tableGrouping', $group)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($invoices);
})->with(['status', 'customer.company_name', 'invoice_date', 'due_date']);

it('renders the maintenance request list with the Starred tab', function (): void {
    Livewire::actingAs(billingListSupportUser())
        ->test(ListMaintenanceRequests::class)
        ->assertSuccessful()
        ->assertSee(__('Starred'))
        ->assertSee(__('Needs Billing'))
        ->assertSeeHtml("selectTableView('preset', 'starred')")
        ->assertSeeHtml("selectTableView('preset', 'unbilled')");
});

it('stars maintenance requests and lists them under the Starred tab', function (): void {
    $user = billingListSupportUser();
    [$starred, $other] = MaintenanceRecord::factory()->count(2)->create()->all();

    Livewire::actingAs($user)
        ->test(ListMaintenanceRequests::class)
        ->callTableColumnAction('is_favorited', $starred)
        ->call('selectTableView', 'preset', 'starred')
        ->assertSet('activeTab', 'starred')
        ->assertCanSeeTableRecords([$starred])
        ->assertCanNotSeeTableRecords([$other]);

    expect($starred->isFavoritedBy($user))->toBeTrue()
        ->and($other->isFavoritedBy($user))->toBeFalse();
});

it('filters maintenance requests with query-builder rules', function (): void {
    $customer = CustomerProfile::factory()->create();
    $open = MaintenanceRecord::factory()->for($customer, 'customer')->create(['status' => MaintenanceStatus::Open]);
    $inProgress = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::InProgress]);
    $covered = MaintenanceRecord::factory()->covered()->create(['status' => MaintenanceStatus::Open]);

    Livewire::actingAs(billingListSupportUser())
        ->test(ListMaintenanceRequests::class)
        ->filterTable('queryBuilder', [
            'rules' => [
                'status-rule' => [
                    'type' => 'status',
                    'data' => ['operator' => 'is', 'settings' => ['values' => [MaintenanceStatus::InProgress->value]]],
                ],
            ],
        ])
        ->assertCanSeeTableRecords([$inProgress])
        ->assertCanNotSeeTableRecords([$open, $covered]);

    Livewire::actingAs(billingListSupportUser())
        ->test(ListMaintenanceRequests::class)
        ->filterTable('queryBuilder', [
            'rules' => [
                'warranty-rule' => [
                    'type' => 'warranty_status',
                    'data' => ['operator' => 'is', 'settings' => ['values' => [WarrantyStatus::Covered->value]]],
                ],
            ],
        ])
        ->assertCanSeeTableRecords([$covered])
        ->assertCanNotSeeTableRecords([$open, $inProgress]);

    Livewire::actingAs(billingListSupportUser())
        ->test(ListMaintenanceRequests::class)
        ->filterTable('queryBuilder', [
            'rules' => [
                'customer-rule' => [
                    'type' => 'customer',
                    'data' => ['operator' => 'isRelatedTo', 'settings' => ['value' => [$customer->getKey()]]],
                ],
            ],
        ])
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$inProgress, $covered]);
});

it('groups the maintenance request list without error', function (string $group): void {
    $records = MaintenanceRecord::factory()->count(2)->create();

    Livewire::actingAs(billingListSupportUser())
        ->test(ListMaintenanceRequests::class)
        ->set('tableGrouping', $group)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($records);
})->with(['status', 'customer.company_name', 'warranty_status', 'billing_type', 'created_at']);
