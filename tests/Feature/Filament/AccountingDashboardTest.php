<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Enums\InvoiceStatus;
use App\Enums\JournalEntryStatus;
use App\Enums\WriteOffStatus;
use App\Filament\Pages\AccountingDashboard;
use App\Filament\Widgets\AccountingLedgerTrend;
use App\Filament\Widgets\AccountingStatistics;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\FiscalPeriod;
use App\Models\ReceivableWriteOff;
use App\Models\JournalEntry;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();
});

it('denies dashboard access to a user with no accounting permissions', function (): void {
    $this->actingAs(User::factory()->create());

    expect(AccountingDashboard::canAccess())->toBeFalse();
});

it('grants dashboard access with any of journal entry, receivable, or payable view permission', function (string $permission): void {
    $user = User::factory()->create();
    $user->givePermissionTo($permission);
    $this->actingAs($user);

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

it('gates the ledger trend widget the same way as the dashboard page', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(AccountingLedgerTrend::canView())->toBeFalse();

    $user->givePermissionTo(AccountingPermission::ReceivableView->value);

    expect(AccountingLedgerTrend::canView())->toBeTrue();
});

it('reports draft journal entries, outstanding receivables and payables, and bills pending approval', function (): void {
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

    Bill::factory()->create(['status' => 'approved', 'total_amount' => 1000, 'amount_paid' => 200]);
    Bill::factory()->create(['status' => 'partially_paid', 'total_amount' => 600, 'amount_paid' => 100]);
    Bill::factory()->create(['status' => 'paid', 'total_amount' => 700, 'amount_paid' => 700]);
    Bill::factory()->count(3)->create(['status' => 'draft']);

    $widget = app(AccountingStatistics::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);
    $values = array_map(fn ($stat) => $stat->getValue(), $stats);

    expect($values)->toBe([
        2,
        number_format(650, 2),
        number_format(1300, 2),
        3,
        number_format(0, 2),
    ]);
});

it('returns a line chart type for the ledger trend widget', function (): void {
    $widget = app(AccountingLedgerTrend::class);

    expect(new ReflectionMethod($widget, 'getType')->invoke($widget))->toBe('line');
});

it('buckets posted journal-entry debit totals by month across the trailing six months', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));

    JournalEntry::factory()->postedAndBalanced('100.00')->create([
        'entry_date' => Carbon::parse('2026-08-05'),
    ]);
    JournalEntry::factory()->postedAndBalanced('250.00')->create([
        'entry_date' => Carbon::parse('2026-06-10'),
    ]);
    // Outside the trailing 6-month window (Mar-Aug 2026) — must be excluded.
    JournalEntry::factory()->postedAndBalanced('999.00')->create([
        'entry_date' => Carbon::parse('2026-01-01'),
    ]);
    // Never posted — must be excluded even though it is dated in-window.
    JournalEntry::factory()->balanced('500.00')->create([
        'status' => JournalEntryStatus::Draft,
        'entry_date' => Carbon::parse('2026-08-06'),
    ]);

    $widget = app(AccountingLedgerTrend::class);
    $data = new ReflectionMethod($widget, 'getData')->invoke($widget);

    expect($data['labels'])->toBe(['Mar 2026', 'Apr 2026', 'May 2026', 'Jun 2026', 'Jul 2026', 'Aug 2026'])
        ->and($data['datasets'][0]['data'])->toBe([0.0, 0.0, 0.0, 250.0, 0.0, 100.0]);

    Carbon::setTestNow();
});


it('reports approved bad debt for the current fiscal period', function (): void {
    $period = FiscalPeriod::factory()->create();

    ReceivableWriteOff::factory()->create([
        'status' => WriteOffStatus::Approved,
        'amount_minor' => 1_000,
        'tax_amount_minor' => 100,
        'fiscal_period_id' => $period->getKey(),
    ]);

    ReceivableWriteOff::factory()->create([
        'status' => WriteOffStatus::Draft,
        'amount_minor' => 9_999,
        'tax_amount_minor' => 0,
        'fiscal_period_id' => $period->getKey(),
    ]);

    $widget = app(AccountingStatistics::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);
    $values = array_map(fn ($stat) => $stat->getValue(), $stats);

    expect($values[4])->toBe('9.00');
});
