<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Enums\InvoiceStatus;
use App\Enums\JournalEntryStatus;
use App\Enums\WriteOffStatus;
use App\Filament\Pages\AccountingDashboard;
use App\Filament\Widgets\AccountingLedgerTrend;
use App\Filament\Widgets\AccountingStatistics;
use App\Filament\Widgets\AccountingTopReceivables;
use App\Filament\Widgets\PeriodCloseReadiness;
use App\Filament\Widgets\TaxPositionThisPeriod;
use App\Models\Bill;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\ReceivableWriteOff;
use App\Models\User;
use App\Support\MoneyFormatter;
use Database\Seeders\AccountingPermissionSeeder;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();
});

/** @return list<Stat> */
function accountingStats(): array
{
    $widget = app(AccountingStatistics::class);

    /** @var list<Stat> */
    return new ReflectionMethod($widget, 'getStats')->invoke($widget);
}

function accountingViewer(string $permission): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permission);

    return $user;
}

it('denies dashboard access to a user with no accounting permissions', function (): void {
    $this->actingAs(User::factory()->create());

    expect(AccountingDashboard::canAccess())->toBeFalse();
});

it('grants dashboard access with any of journal entry, receivable, or payable view permission', function (string $permission): void {
    $this->actingAs(accountingViewer($permission));

    expect(AccountingDashboard::canAccess())->toBeTrue();
})->with([
    'journal entry view' => AccountingPermission::JournalEntryView->value,
    'receivable view' => AccountingPermission::ReceivableView->value,
    'payable view' => AccountingPermission::PayableView->value,
]);

it('gates the statistics widget the same way as the dashboard page', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(AccountingStatistics::canView())->toBeFalse();

    $user->givePermissionTo(AccountingPermission::PayableView->value);

    expect(AccountingStatistics::canView())->toBeTrue();
});

it('grants the statistics widget with only receivable view permission', function (): void {
    $this->actingAs(accountingViewer(AccountingPermission::ReceivableView->value));

    expect(AccountingStatistics::canView())->toBeTrue();
});

it('gates the ledger trend widget the same way as the dashboard page', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(AccountingLedgerTrend::canView())->toBeFalse();

    $user->givePermissionTo(AccountingPermission::ReceivableView->value);

    expect(AccountingLedgerTrend::canView())->toBeTrue();
});

it('gates the top receivables table on receivable view', function (): void {
    $this->actingAs(accountingViewer(AccountingPermission::PayableView->value));

    expect(AccountingTopReceivables::canView())->toBeFalse();

    $this->actingAs(accountingViewer(AccountingPermission::ReceivableView->value));

    expect(AccountingTopReceivables::canView())->toBeTrue();
});

it('reports receivable and payable balances, the net tax position and drafts awaiting action', function (): void {
    JournalEntry::factory()->count(2)->create();
    JournalEntry::factory()->postedAndBalanced('50.00')->create();

    Invoice::factory()->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => 500,
        'amount_paid' => 100,
    ]);
    Invoice::factory()->create([
        'status' => InvoiceStatus::Sent,
        'issued_at' => now(),
        'sent_at' => now(),
        'total_amount' => 300,
        'amount_paid' => 50,
    ]);
    Invoice::factory()->create([
        'status' => InvoiceStatus::Sent,
        'issued_at' => now(),
        'sent_at' => now(),
        'total_amount' => 200,
        'amount_paid' => 200,
    ]);
    Invoice::factory()->create([
        'status' => InvoiceStatus::Draft,
        'issued_at' => null,
        'total_amount' => 400,
        'amount_paid' => 0,
    ]);

    Bill::factory()->create(['status' => 'approved', 'total_amount' => 1000, 'amount_paid' => 200, 'bill_date' => today()]);
    Bill::factory()->create(['status' => 'partially_paid', 'total_amount' => 600, 'amount_paid' => 100, 'bill_date' => today()]);
    Bill::factory()->create(['status' => 'paid', 'total_amount' => 700, 'amount_paid' => 700, 'bill_date' => today()->subYear()]);
    Bill::factory()->count(3)->create(['status' => 'draft']);

    $stats = accountingStats();

    expect($stats)->toHaveCount(4)
        ->and(array_map(fn (Stat $stat): mixed => $stat->getValue(), $stats))->toBe([
            MoneyFormatter::formatAmount(650),
            MoneyFormatter::formatAmount(1300),
            MoneyFormatter::formatAmount(0),
            '5',
        ])
        ->and((string) $stats[1]->getDescription())->toBe('Billed in period: '.MoneyFormatter::formatAmount(1600))
        ->and(array_sum($stats[0]->getChart() ?? []))->toBe(1000.0)
        ->and((string) $stats[3]->getDescription())->toBe('2 draft entries · 3 bills to approve')
        ->and($stats[3]->getColor())->toBe('warning');
});

it('marks awaiting action as clear when nothing is in draft', function (): void {
    expect(accountingStats()[3]->getColor())->toBe('success');
});

it('reports bad debt approved within the selected period', function (): void {
    $period = FiscalPeriod::factory()->create();

    ReceivableWriteOff::factory()->create([
        'status' => WriteOffStatus::Approved,
        'amount_minor' => 1_000,
        'tax_amount_minor' => 100,
        'fiscal_period_id' => $period->getKey(),
        'approved_at' => now(),
    ]);
    ReceivableWriteOff::factory()->create([
        'status' => WriteOffStatus::Approved,
        'amount_minor' => 5_000,
        'tax_amount_minor' => 0,
        'fiscal_period_id' => $period->getKey(),
        'approved_at' => now()->subDays(60),
    ]);
    ReceivableWriteOff::factory()->create([
        'status' => WriteOffStatus::Draft,
        'amount_minor' => 9_999,
        'tax_amount_minor' => 0,
        'fiscal_period_id' => $period->getKey(),
    ]);

    expect((string) accountingStats()[0]->getDescription())->toBe('Bad debt in period: '.MoneyFormatter::format(900));
});

it('returns a line chart type for the ledger trend widget', function (): void {
    $widget = app(AccountingLedgerTrend::class);

    expect(new ReflectionMethod($widget, 'getType')->invoke($widget))->toBe('line')
        ->and($widget->getHeading())->toBe('Posted journal activity');
});

it('buckets posted journal-entry debits across the selected period and the previous one', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));

    JournalEntry::factory()->postedAndBalanced('100.00')->create([
        'entry_date' => Carbon::parse('2026-08-05'),
    ]);
    JournalEntry::factory()->postedAndBalanced('250.00')->create([
        'entry_date' => Carbon::parse('2026-06-10'),
    ]);
    // Two years back — outside both the selected and the previous window.
    JournalEntry::factory()->postedAndBalanced('999.00')->create([
        'entry_date' => Carbon::parse('2024-01-01'),
    ]);
    // Never posted — must be excluded even though it is dated in-window.
    JournalEntry::factory()->balanced('500.00')->create([
        'status' => JournalEntryStatus::Draft,
        'entry_date' => Carbon::parse('2026-08-06'),
    ]);

    $widget = app(AccountingLedgerTrend::class);
    $widget->pageFilters = ['period' => 'this_year'];

    $data = new ReflectionMethod($widget, 'getData')->invoke($widget);

    expect($data['labels'])->toBe(['Jan 2026', 'Feb 2026', 'Mar 2026', 'Apr 2026', 'May 2026', 'Jun 2026', 'Jul 2026', 'Aug 2026'])
        ->and($data['datasets'][0]['data'])->toBe([0.0, 0.0, 0.0, 0.0, 0.0, 250.0, 0.0, 100.0])
        ->and(array_sum($data['datasets'][1]['data']))->toBe(0.0);

    $widget->pageFilters = ['period' => 'custom', 'customFrom' => '2026-06-01', 'customUntil' => '2026-06-30'];
    $data = new ReflectionMethod($widget, 'getData')->invoke($widget);

    expect(array_sum($data['datasets'][0]['data']))->toBe(250.0);

    Carbon::setTestNow();
});

it('breaks the selected period tax position into a bar chart', function (): void {
    $widget = app(TaxPositionThisPeriod::class);
    $data = new ReflectionMethod($widget, 'getData')->invoke($widget);

    expect(new ReflectionMethod($widget, 'getType')->invoke($widget))->toBe('bar')
        ->and($data['labels'])->toHaveCount(5)
        ->and($data['datasets'][0]['data'])->toBe([0.0, 0.0, 0.0, 0.0, 0.0])
        ->and($widget->getHeading())->toBe('Tax position');
});

it('lists customers by outstanding receivable balance', function (): void {
    $this->actingAs(accountingViewer(AccountingPermission::ReceivableView->value));

    $big = CustomerProfile::factory()->create(['company_name' => 'Big Balance Clinic']);
    $settled = CustomerProfile::factory()->create(['company_name' => 'Settled Clinic']);

    Invoice::factory()->create(['customer_id' => $big->id, 'status' => InvoiceStatus::Issued, 'issued_at' => now(), 'total_amount' => 900, 'amount_paid' => 0]);
    Invoice::factory()->create(['customer_id' => $big->id, 'status' => InvoiceStatus::Issued, 'issued_at' => now(), 'total_amount' => 100, 'amount_paid' => 0]);
    Invoice::factory()->create(['customer_id' => $settled->id, 'status' => InvoiceStatus::Issued, 'issued_at' => now(), 'total_amount' => 100, 'amount_paid' => 100]);

    Livewire::test(AccountingTopReceivables::class)
        ->assertSee('Top outstanding customers')
        ->assertSee('Big Balance Clinic')
        ->assertSee(MoneyFormatter::formatAmount(1000))
        ->assertDontSee('Settled Clinic');
});

it('shows the open period close checklist from the last measurement', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(PeriodCloseReadiness::class)
        ->assertSee(__('admin.accounting.close_readiness.no_open_period'));

    FiscalPeriod::factory()->create(['name' => 'Checklist Period', 'is_closed' => false]);

    Livewire::test(PeriodCloseReadiness::class)
        ->assertSee('Last measured checks for Checklist Period')
        ->assertSee('Not measured');
});

it('lays out the accounting dashboard in aligned pairs', function (): void {
    $widgets = new ReflectionMethod(AccountingDashboard::class, 'getDashboardWidgets')->invoke(new AccountingDashboard);

    expect($widgets)->toBe([
        AccountingStatistics::class,
        [AccountingLedgerTrend::class, TaxPositionThisPeriod::class],
        [PeriodCloseReadiness::class, AccountingTopReceivables::class],
    ]);

    $this->actingAs(accountingViewer(AccountingPermission::JournalEntryView->value));

    Livewire::test(AccountingDashboard::class)->assertSuccessful();
});
