<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Widgets\InvoicesOverview;
use App\Models\CustomerProfile;
use App\Models\DepositApplicationIssue;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Payments\CustomerDepositApplicationService;
use App\Services\Sales\InvoiceService;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
});

function invoiceSalesUser(bool $billing = false): User
{
    $user = User::factory()->admin()->create();
    $user->assignRole(($billing ? DashboardRole::BillingOfficer : DashboardRole::SalesOfficer)->value);

    return $user;
}

it('scopes unpaid invoices to active ones with nothing paid or credited', function (): void {
    Invoice::factory()->create(['status' => 'issued', 'total_amount' => 100, 'amount_paid' => 0, 'credited_amount' => 0]);
    Invoice::factory()->create(['status' => 'issued', 'total_amount' => 100, 'amount_paid' => 50, 'credited_amount' => 0]);
    Invoice::factory()->create(['status' => 'draft', 'total_amount' => 100, 'amount_paid' => 0, 'credited_amount' => 0]);

    expect(Invoice::query()->unpaid()->count())->toBe(1);
});

it('scopes partially paid invoices to active ones with some payment applied', function (): void {
    Invoice::factory()->create(['status' => 'issued', 'total_amount' => 100, 'amount_paid' => 50, 'credited_amount' => 0]);
    Invoice::factory()->create(['status' => 'issued', 'total_amount' => 100, 'amount_paid' => 100, 'credited_amount' => 0]);
    Invoice::factory()->create(['status' => 'issued', 'total_amount' => 100, 'amount_paid' => 0, 'credited_amount' => 0]);

    expect(Invoice::query()->partiallyPaid()->count())->toBe(1);
});

it('scopes overdue invoices to active ones past due date with a balance remaining', function (): void {
    Invoice::factory()->create([
        'status' => 'issued', 'total_amount' => 100, 'amount_paid' => 0, 'credited_amount' => 0,
        'due_date' => today()->subDays(5),
    ]);
    Invoice::factory()->create([
        'status' => 'issued', 'total_amount' => 100, 'amount_paid' => 100, 'credited_amount' => 0,
        'due_date' => today()->subDays(5),
    ]);
    Invoice::factory()->create([
        'status' => 'issued', 'total_amount' => 100, 'amount_paid' => 0, 'credited_amount' => 0,
        'due_date' => today()->addDays(5),
    ]);

    expect(Invoice::query()->overdue()->count())->toBe(1);
});

it('scopes settled invoices to active ones with nothing left to collect', function (): void {
    Invoice::factory()->create(['status' => 'issued', 'total_amount' => 100, 'amount_paid' => 100, 'credited_amount' => 0]);
    Invoice::factory()->create(['status' => 'issued', 'total_amount' => 100, 'amount_paid' => 0, 'credited_amount' => 100]);
    Invoice::factory()->create(['status' => 'issued', 'total_amount' => 100, 'amount_paid' => 50, 'credited_amount' => 0]);

    expect(Invoice::query()->settled()->count())->toBe(2);
});

it('scopes invoices needing attention to overdue or unresolved deposit issues', function (): void {
    $overdue = Invoice::factory()->create([
        'status' => 'issued', 'total_amount' => 100, 'amount_paid' => 0, 'credited_amount' => 0,
        'due_date' => today()->subDays(5),
    ]);
    $withOpenIssue = Invoice::factory()->create([
        'status' => 'issued', 'total_amount' => 100, 'amount_paid' => 0, 'credited_amount' => 0,
        'due_date' => today()->addDays(5),
    ]);
    DepositApplicationIssue::query()->create([
        'invoice_id' => $withOpenIssue->getKey(),
        'error_message' => 'coverage',
        'occurred_at' => now(),
    ]);
    Invoice::factory()->create([
        'status' => 'issued', 'total_amount' => 100, 'amount_paid' => 100, 'credited_amount' => 0,
        'due_date' => today()->addDays(5),
    ]);

    expect(Invoice::query()->needsAttention()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$overdue->id, $withOpenIssue->id])->sort()->values()->all());
});

it('scopes invoices issued this month', function (): void {
    Invoice::factory()->create(['issued_at' => now()]);
    Invoice::factory()->create(['issued_at' => now()->subMonths(2)]);
    Invoice::factory()->create(['issued_at' => null]);

    expect(Invoice::query()->issuedThisMonth()->count())->toBe(1);
});

it('renders the invoices overview stats widget and list page', function (): void {
    Invoice::factory()->create(['issued_at' => now(), 'status' => 'issued']);

    $widget = Livewire::actingAs(invoiceSalesUser())
        ->test(InvoicesOverview::class)
        ->assertSuccessful();

    $widget
        ->assertSee('--cols-default: repeat(1, minmax(0, 1fr))')
        ->assertSee('--cols-cmd: repeat(2, minmax(0, 1fr))')
        ->assertSee('--cols-cxl: repeat(3, minmax(0, 1fr))')
        ->assertSee('--cols-c5xl: repeat(5, minmax(0, 1fr))');

    Livewire::actingAs(invoiceSalesUser())
        ->test(ListInvoices::class)
        ->assertSuccessful();
});

it('filters invoices by customer', function (): void {
    $matching = CustomerProfile::factory()->create();
    $other = CustomerProfile::factory()->create();
    $wanted = Invoice::factory()->for($matching, 'customer')->create();
    $unwanted = Invoice::factory()->for($other, 'customer')->create();

    Livewire::actingAs(invoiceSalesUser())
        ->test(ListInvoices::class)
        ->filterTable('queryBuilder', [
            'rules' => [
                'customer-rule' => [
                    'type' => 'customer',
                    'data' => ['operator' => 'isRelatedTo', 'settings' => ['value' => [$matching->getKey()]]],
                ],
            ],
        ])
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$unwanted]);
});

it('opens the invoice list on the tab each overview stat links to', function (): void {
    $user = invoiceSalesUser();

    $widget = Livewire::actingAs($user)->test(InvoicesOverview::class);
    $stats = new ReflectionMethod($widget->instance(), 'getStats')->invoke($widget->instance());

    $tabs = [];
    foreach ($stats as $stat) {
        $url = $stat->getUrl();
        if ($url === null) {
            continue;
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $tabs[] = $query['tab'] ?? null;

        Livewire::actingAs($user)
            ->withQueryParams($query)
            ->test(ListInvoices::class)
            ->assertSet('activeTab', $query['tab']);
    }

    expect($tabs)->not->toBeEmpty()->not->toContain(null);
});

it('offers exactly the primary action the next-action resolver names for each invoice state', function (): void {
    $draft = Invoice::factory()->create(['status' => InvoiceStatus::Draft]);
    $outstanding = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued, 'issued_at' => now(),
        'total_amount' => 100, 'amount_paid' => 0, 'credited_amount' => 0,
    ]);
    $withIssue = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued, 'issued_at' => now(),
        'total_amount' => 100, 'amount_paid' => 0, 'credited_amount' => 0,
    ]);
    DepositApplicationIssue::query()->create([
        'invoice_id' => $withIssue->getKey(),
        'error_message' => 'coverage',
        'occurred_at' => now(),
    ]);
    $paid = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued, 'issued_at' => now(),
        'total_amount' => 100, 'amount_paid' => 100, 'credited_amount' => 0,
    ]);
    $cancelled = Invoice::factory()->create(['status' => InvoiceStatus::Cancelled, 'issued_at' => now(), 'total_amount' => 100]);
    $writtenOff = Invoice::factory()->create(['status' => InvoiceStatus::WrittenOff, 'issued_at' => now(), 'total_amount' => 100]);

    $primary = ['issue', 'retry_deposit_application', 'record_payment'];
    $expected = [
        [$draft, 'issue'],
        [$withIssue, 'retry_deposit_application'],
        [$outstanding, 'record_payment'],
        [$paid, null],
        [$cancelled, null],
        [$writtenOff, null],
    ];

    $component = Livewire::actingAs(invoiceSalesUser(true))->test(ListInvoices::class);

    foreach ($expected as [$invoice, $action]) {
        foreach ($primary as $name) {
            $name === $action
                ? $component->assertTableActionVisible($name, $invoice)
                : $component->assertTableActionHidden($name, $invoice);
        }
    }
});

it('issues a draft invoice once through the invoice service from the list row action', function (): void {
    $calls = new class
    {
        public int $issued = 0;
    };
    app()->instance(InvoiceService::class, new class($calls)
    {
        public function __construct(private object $calls) {}

        public function issue(User $actor, Invoice $invoice): Invoice
        {
            $this->calls->issued++;
            $invoice->forceFill(['status' => InvoiceStatus::Issued, 'issued_at' => now()])->save();

            return $invoice;
        }
    });

    $draft = Invoice::factory()->create(['status' => InvoiceStatus::Draft, 'total_amount' => 100]);

    Livewire::actingAs(invoiceSalesUser(true))
        ->test(ListInvoices::class)
        ->callTableAction('issue', $draft)
        ->assertTableActionHidden('issue', $draft->refresh());

    expect($calls->issued)->toBe(1)
        ->and($draft->refresh()->status)->toBe(InvoiceStatus::Issued);
});

it('retries deposit application once and resolves the open issue', function (): void {
    $calls = new class
    {
        public int $applied = 0;
    };
    app()->instance(CustomerDepositApplicationService::class, new class($calls)
    {
        public function __construct(private object $calls) {}

        public function applyEligibleDeposits(Invoice $invoice): Invoice
        {
            $this->calls->applied++;

            return $invoice;
        }
    });

    $invoice = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued, 'issued_at' => now(),
        'total_amount' => 100, 'amount_paid' => 0, 'credited_amount' => 0,
    ]);
    DepositApplicationIssue::query()->create([
        'invoice_id' => $invoice->getKey(),
        'error_message' => 'coverage',
        'occurred_at' => now(),
    ]);

    Livewire::actingAs(invoiceSalesUser(true))
        ->test(ListInvoices::class)
        ->callTableAction('retry_deposit_application', $invoice)
        ->assertTableActionHidden('retry_deposit_application', $invoice)
        ->assertTableActionVisible('record_payment', $invoice);

    expect($calls->applied)->toBe(1)
        ->and(DepositApplicationIssue::query()->whereNull('resolved_at')->count())->toBe(0);
});

it('hides issue and retry from users who cannot issue invoices', function (): void {
    $draft = Invoice::factory()->create(['status' => InvoiceStatus::Draft]);
    $withIssue = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued, 'issued_at' => now(),
        'total_amount' => 100, 'amount_paid' => 0, 'credited_amount' => 0,
    ]);
    DepositApplicationIssue::query()->create([
        'invoice_id' => $withIssue->getKey(),
        'error_message' => 'coverage',
        'occurred_at' => now(),
    ]);

    Livewire::actingAs(invoiceSalesUser())
        ->test(ListInvoices::class)
        ->assertTableActionHidden('issue', $draft)
        ->assertTableActionHidden('retry_deposit_application', $withIssue);
});
