<?php

declare(strict_types=1);

use App\Enums\CrmPermission;
use App\Enums\EmployeePermission;
use App\Enums\SalesPermission;
use App\Enums\SupportPermission;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\CustomerTimeline;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\CustomerInteractionsRelationManager;
use App\Filament\Resources\Customers\RelationManagers\CustomerInvoicesRelationManager;
use App\Filament\Resources\Customers\RelationManagers\CustomerQuotationsRelationManager;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Ticket;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
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

it('renders labelled statuses, formatted money, and date-group headers instead of raw values', function (): void {
    $customer = CustomerProfile::factory()->create();
    Ticket::factory()->create(['customer_id' => $customer->getKey(), 'status' => 'in_progress']);
    Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'invoice_date' => now(),
        'total_amount' => '150.00',
        'status' => 'issued',
    ]);

    $actor = customerFullAccessActor();

    Livewire::actingAs($actor)
        ->test(CustomerTimeline::class, ['record' => $customer->getKey()])
        ->assertSuccessful()
        ->assertSee('In progress')
        ->assertDontSee('in_progress')
        ->assertSee('Issued')
        ->assertSee('150.00')
        ->assertSee('Today');
});

it('round-trips the range, search, and activity toggle URL props', function (): void {
    $customer = CustomerProfile::factory()->create();
    $actor = customerFullAccessActor();

    Livewire::actingAs($actor)
        ->test(CustomerTimeline::class, ['record' => $customer->getKey()])
        ->assertSet('range', 'all')
        ->assertSet('search', null)
        ->assertSet('showActivity', true)
        ->set('range', '30d')
        ->set('search', 'INV-000001')
        ->set('showActivity', false)
        ->assertSuccessful()
        ->assertSet('range', '30d')
        ->assertSet('search', 'INV-000001')
        ->assertSet('showActivity', false);
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

it('logs customer interactions from the relation manager and covers scalar guards', function (): void {
    $customer = CustomerProfile::factory()->create();
    $actor = customerFullAccessActor();
    $actor->givePermissionTo(Permission::findOrCreate(CrmPermission::InteractionCreate->value, 'web'));

    Livewire::actingAs($actor)
        ->test(CustomerInteractionsRelationManager::class, [
            'ownerRecord' => $customer,
            'pageClass' => ViewCustomer::class,
        ])
        ->callAction(TestAction::make('log_interaction')->table(), [
            'type' => 'call',
            'direction' => 'outbound',
            'outcome' => 'positive',
            'occurred_at' => now()->toDateTimeString(),
            'summary' => 'Coverage interaction',
            'notes' => 'Coverage notes',
        ])
        ->assertHasNoActionErrors();

    expect($customer->interactions()->where('summary', 'Coverage interaction')->exists())->toBeTrue();

    $stringValue = new ReflectionMethod(CustomerInteractionsRelationManager::class, 'stringValue');
    expect($stringValue->invoke(null, 123, 'field'))->toBe('123');
    expect(fn (): mixed => $stringValue->invoke(null, [], 'field'))
        ->toThrow(LogicException::class, 'Expected field.');
});

it('covers customer timeline actions export filters ranges and scalar guards', function (): void {
    $customer = CustomerProfile::factory()->create();
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'invoice_date' => now(),
        'total_amount' => '125.50',
        'status' => 'issued',
    ]);

    $actor = customerFullAccessActor();
    $actor->givePermissionTo(Permission::findOrCreate(CrmPermission::InteractionCreate->value, 'web'));

    $test = Livewire::actingAs($actor)
        ->test(CustomerTimeline::class, ['record' => $customer->getKey()])
        ->callAction('log_interaction', [
            'type' => 'call',
            'direction' => 'outbound',
            'outcome' => 'positive',
            'occurred_at' => now()->toDateTimeString(),
            'summary' => 'Timeline header action coverage',
            'notes' => 123,
        ])
        ->assertNotified();

    expect($customer->interactions()->where('summary', 'Timeline header action coverage')->exists())->toBeTrue();

    $page = $test->instance();

    $range = new ReflectionMethod(CustomerTimeline::class, 'resolveRange');

    $page->range = '30d';
    [$from30, $until30] = $range->invoke($page);
    expect($from30)->not->toBeNull()->and($until30)->toBeNull();

    $page->range = '90d';
    [$from90, $until90] = $range->invoke($page);
    expect($from90)->not->toBeNull()->and($until90)->toBeNull();

    $page->range = 'ytd';
    [$fromYtd, $untilYtd] = $range->invoke($page);
    expect($fromYtd?->isStartOfYear())->toBeTrue()->and($untilYtd)->toBeNull();

    $page->range = 'custom';
    $page->from = now()->subDays(5)->toDateTimeString();
    $page->until = now()->subDay()->toDateTimeString();
    [$fromCustom, $untilCustom] = $range->invoke($page);
    expect($fromCustom)->not->toBeNull()->and($untilCustom)->not->toBeNull();

    $page->range = 'all';
    $page->from = null;
    $page->until = null;
    $page->types = ['invoice'];
    $page->search = $invoice->invoice_number;
    $page->showActivity = false;

    $export = new ReflectionMethod(CustomerTimeline::class, 'exportCsv');
    $response = $export->invoke($page);

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    expect($csv)
        ->toContain('Date,Type,Title,Status,Amount,Actor')
        ->toContain('invoice')
        ->toContain('125.50');

    $actions = collect($page->getHeaderActions())->keyBy(static fn ($action): string => $action->getName());
    expect($actions->get('back_to_customer')?->getUrl())
        ->toContain((string) $customer->getKey());

    $page->types = ['invoice'];
    $page->search = 'coverage';
    $page->range = '90d';
    $page->showActivity = false;
    $page->clearFilters();

    expect($page->types)->toBe([])
        ->and($page->search)->toBeNull()
        ->and($page->range)->toBe('all')
        ->and($page->showActivity)->toBeTrue();

    $stringValue = new ReflectionMethod(CustomerTimeline::class, 'stringValue');
    expect($stringValue->invoke(null, 123, 'field'))->toBe('123')
        ->and(fn (): mixed => $stringValue->invoke(null, [], 'field'))
        ->toThrow(LogicException::class, 'Expected field.');

    $parseDate = new ReflectionMethod(CustomerTimeline::class, 'parseDate');
    expect($parseDate->invoke($page, null))->toBeNull()
        ->and($parseDate->invoke($page, ''))->toBeNull()
        ->and($parseDate->invoke($page, '2026-09-19'))->not->toBeNull();
});
