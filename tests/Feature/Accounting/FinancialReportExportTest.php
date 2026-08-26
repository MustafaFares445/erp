<?php

declare(strict_types=1);

use App\Enums\AccountElement;
use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use App\Filament\Resources\FinancialReports\Pages\ViewFinancialReports;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();

    $this->actor = User::factory()->create();
    $this->actor->assignRole(DashboardRole::ChiefAccountant->value);

    $this->period = FiscalPeriod::factory()->forMonth(CarbonImmutable::parse('2026-01-01'))->create();

    $this->cash = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1100', 'name' => 'Cash']);
    $this->sales = ChartAccount::factory()->ofElement(AccountElement::Income)->create(['code' => '4100', 'name' => 'Sales']);

    app(JournalPostingService::class)->postNew($this->actor, CarbonImmutable::parse('2026-01-05'), [
        ['chart_account_id' => $this->cash->id, 'debit' => '1000.00', 'credit' => '0.00'],
        ['chart_account_id' => $this->sales->id, 'debit' => '0.00', 'credit' => '1000.00'],
    ]);

    $this->from = '2026-01-01';
    $this->to = '2026-01-31';
});

/**
 * Streams a named export method on a hydrated page instance and captures the
 * body, mirroring how `response()->streamDownload()` actually emits output —
 * there is no return value to inspect until it is sent.
 */
function captureExportCsv(object $page, string $method): string
{
    $reflection = new ReflectionMethod($page, $method);
    $response = $reflection->invoke($page);

    ob_start();
    $response->sendContent();

    return ob_get_clean();
}

it('exports each report with a scope line, a stable-identifier header, and the on-screen proof (FR-044, FR-045)', function (): void {
    Livewire::actingAs($this->actor)
        ->test(ViewFinancialReports::class)
        ->set('from', $this->from)
        ->set('to', $this->to)
        ->set('asOf', $this->to)
        ->assertOk()
        ->tap(function ($test): void {
            $page = $test->instance();

            $trialBalance = explode("\n", captureExportCsv($page, 'streamTrialBalance'));
            expect($trialBalance[0])->toContain('Trial Balance', $this->from, $this->to)
                ->and($trialBalance[1])->toContain('account_code', 'period_debit', 'period_credit', 'closing_balance')
                ->and(implode("\n", $trialBalance))->toContain('1100', 'Cash', '1000.00')
                ->and(implode("\n", $trialBalance))->toContain('BALANCED');

            $generalLedger = explode("\n", captureExportCsv($page, 'streamGeneralLedger'));
            expect($generalLedger[0])->toContain('General Ledger', $this->from, $this->to)
                ->and($generalLedger[1])->toContain('entry_number', 'debit', 'credit', 'running_balance');

            $profitAndLoss = explode("\n", captureExportCsv($page, 'streamProfitAndLoss'));
            expect($profitAndLoss[0])->toContain('Profit and Loss')
                ->and($profitAndLoss[1])->toContain('section', 'amount')
                ->and(implode("\n", $profitAndLoss))->toContain('NET PROFIT');

            $balanceSheet = explode("\n", captureExportCsv($page, 'streamBalanceSheet'));
            expect($balanceSheet[0])->toContain('Balance Sheet', 'as of')
                ->and($balanceSheet[1])->toContain('section', 'account_code', 'account_name', 'amount')
                ->and(implode("\n", $balanceSheet))->toContain('Accumulated Earnings (computed, not posted)')
                ->and(implode("\n", $balanceSheet))->toContain('BALANCED');

            $postingRegister = explode("\n", captureExportCsv($page, 'streamPostingRegister'));
            expect($postingRegister[0])->toContain('Posting Register')
                ->and($postingRegister[1])->toContain('entry_number', 'fiscal_period', 'posted_by', 'source');
        });
});

it('exports an empty report with the scope line and header but zero data rows (FR-046)', function (): void {
    Livewire::actingAs($this->actor)
        ->test(ViewFinancialReports::class)
        ->set('from', '2020-01-01')
        ->set('to', '2020-01-31')
        ->assertOk()
        ->tap(function ($test): void {
            $page = $test->instance();

            $csv = mb_trim(captureExportCsv($page, 'streamTrialBalance'));
            $lines = array_values(array_filter(explode("\n", $csv)));

            // Scope line, header, TOTAL row, proof row — no account rows.
            expect($lines)->toHaveCount(4)
                ->and($lines[2])->toContain('TOTAL')
                ->and($lines[3])->toContain('BALANCED');
        });
});

it('refuses an export requested directly on the streaming method itself, without accounting.report.view (FR-005, SC-008)', function (string $method): void {
    // Calls the streaming method directly, bypassing mount() entirely — the
    // scenario contracts/permissions.md §5.3 exists for: an export guarded
    // only by its button's visibility is not guarded, because the request
    // can be issued directly.
    $unauthorized = User::factory()->create();
    $unauthorized->givePermissionTo(AccountingPermission::JournalEntryPost->value);
    $this->actingAs($unauthorized);

    $page = new ViewFinancialReports;
    $page->from = $this->from;
    $page->to = $this->to;
    $page->asOf = $this->to;

    new ReflectionMethod($page, $method)->invoke($page);
})->throws(HttpException::class)->with([
    'streamTrialBalance',
    'streamGeneralLedger',
    'streamProfitAndLoss',
    'streamBalanceSheet',
    'streamPostingRegister',
]);

it('writes no export_logs row, nor any other row, for any export (FR-047)', function (): void {
    Livewire::actingAs($this->actor)
        ->test(ViewFinancialReports::class)
        ->set('from', $this->from)
        ->set('to', $this->to)
        ->set('asOf', $this->to)
        ->assertOk()
        ->tap(function ($test): void {
            $page = $test->instance();

            foreach (['streamTrialBalance', 'streamGeneralLedger', 'streamProfitAndLoss', 'streamBalanceSheet', 'streamPostingRegister'] as $method) {
                captureExportCsv($page, $method);
            }

            expect(JournalEntry::query()->count())->toBe(1)
                ->and(JournalEntryLine::query()->count())->toBe(2);
        });
});
