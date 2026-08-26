<?php

declare(strict_types=1);

use App\Enums\AccountElement;
use App\Enums\DashboardRole;
use App\Filament\Resources\FinancialReports\Pages\ViewFinancialReports;
use App\Models\AccountType;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\JournalPostingService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Invariant I-9 (FR-052, SC-010): producing and exporting every one of the
 * five reports leaves the row counts of every accounting table identical.
 */
it('writes no row to any accounting table while producing and exporting all five reports', function (): void {
    (new AccountingPermissionSeeder)->run();

    $actor = User::factory()->create();
    $actor->assignRole(DashboardRole::ChiefAccountant->value);

    FiscalPeriod::factory()->forMonth(CarbonImmutable::parse('2026-01-01'))->create();

    $cash = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1100']);
    $sales = ChartAccount::factory()->ofElement(AccountElement::Income)->create(['code' => '4100']);

    app(JournalPostingService::class)->postNew($actor, CarbonImmutable::parse('2026-01-10'), [
        ['chart_account_id' => $cash->id, 'debit' => '250.00', 'credit' => '0.00'],
        ['chart_account_id' => $sales->id, 'debit' => '0.00', 'credit' => '250.00'],
    ]);

    $tableCounts = fn (): array => [
        'account_types' => AccountType::query()->count(),
        'chart_accounts' => ChartAccount::query()->withTrashed()->count(),
        'fiscal_periods' => FiscalPeriod::query()->count(),
        'journal_entries' => JournalEntry::query()->count(),
        'journal_entry_lines' => JournalEntryLine::query()->count(),
    ];

    $before = $tableCounts();

    $service = app(FinancialReportService::class);
    $from = CarbonImmutable::parse('2026-01-01');
    $to = CarbonImmutable::parse('2026-01-31');

    $service->trialBalance($from, $to);
    $service->generalLedger($from, $to, null, 25);
    $service->profitAndLoss($from, $to);
    $service->balanceSheet($to);
    $service->postingRegister($from, $to, 25);

    Livewire::actingAs($actor)
        ->test(ViewFinancialReports::class)
        ->set('from', '2026-01-01')
        ->set('to', '2026-01-31')
        ->set('asOf', '2026-01-31')
        ->assertOk()
        ->tap(function ($test): void {
            $page = $test->instance();

            foreach (['streamTrialBalance', 'streamGeneralLedger', 'streamProfitAndLoss', 'streamBalanceSheet', 'streamPostingRegister'] as $method) {
                $response = new ReflectionMethod($page, $method)->invoke($page);
                ob_start();
                $response->sendContent();
                ob_get_clean();
            }
        });

    expect($tableCounts())->toBe($before);
});
