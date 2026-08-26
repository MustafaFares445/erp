<?php

declare(strict_types=1);

namespace App\Filament\Resources\FinancialReports\Pages;

use App\Enums\AccountingPermission;
use App\Enums\FinancialReportType;
use App\Filament\Resources\FinancialReports\FinancialReportResource;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\Accounting\FinancialReportService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The report-type selector, its filters, and the dispatch to
 * {@see FinancialReportService} for whichever of the five reports is
 * currently selected.
 *
 * The permission is re-checked here as well as on
 * {@see FinancialReportResource}, so a direct URL cannot bypass the resource
 * gate (contracts/permissions.md §5).
 */
final class ViewFinancialReports extends Page
{
    protected static string $resource = FinancialReportResource::class;

    protected string $view = 'filament.financial-reports.view-financial-reports';

    #[Url]
    public string $reportType = 'trial_balance';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    #[Url]
    public ?string $asOf = null;

    #[Url]
    public ?int $fiscalPeriodId = null;

    #[Url]
    public ?int $accountId = null;

    public function mount(): void
    {
        $this->authorizeReportAccess();

        $today = CarbonImmutable::now();

        $this->from ??= $today->startOfMonth()->toDateString();
        $this->to ??= $today->toDateString();
        $this->asOf ??= $today->toDateString();
    }

    public function updatedFiscalPeriodId(): void
    {
        $period = FiscalPeriod::query()->find($this->fiscalPeriodId);

        if (! $period instanceof FiscalPeriod) {
            return;
        }

        $this->from = $period->starts_at->toDateString();
        $this->to = $period->ends_at->toDateString();
        $this->asOf = $period->ends_at->toDateString();
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.financial_reports');
    }

    /** @return list<array{value: string, label: string}> */
    public function reportTypeOptions(): array
    {
        return array_map(
            static fn (FinancialReportType $type): array => ['value' => $type->value, 'label' => $type->label()],
            FinancialReportType::cases(),
        );
    }

    /** @return array<int, string> */
    public function fiscalPeriodOptions(): array
    {
        return app(FinancialReportService::class)->fiscalPeriodOptions();
    }

    /** @return array<int, string> */
    public function accountOptions(): array
    {
        return ChartAccount::query()
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (ChartAccount $account): array => [$account->id => $account->code.' '.$account->name])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getViewData(): array
    {
        $this->authorizeReportAccess();

        $service = app(FinancialReportService::class);
        $type = FinancialReportType::from($this->reportType);
        $from = CarbonImmutable::parse($this->from);
        $to = CarbonImmutable::parse($this->to);
        $asOf = CarbonImmutable::parse($this->asOf);

        return [
            'reportType' => $type,
            'report' => match ($type) {
                FinancialReportType::TrialBalance => $service->trialBalance($from, $to),
                FinancialReportType::ProfitAndLoss => $service->profitAndLoss($from, $to),
                FinancialReportType::BalanceSheet => $service->balanceSheet($asOf),
                FinancialReportType::GeneralLedger => $service->generalLedger($from, $to, $this->accountId, 25),
                FinancialReportType::PostingRegister => $service->postingRegister($from, $to, 25),
            },
        ];
    }

    /**
     * Five export actions, one per report, each gated three ways
     * (contracts/permissions.md §5.3): `visible()`, `authorize()`, and a
     * permission re-check inside the streaming method itself — the third is
     * the one that matters, because an export guarded only by its button's
     * visibility can be requested directly.
     *
     * @return array<int, Action>
     */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportTrialBalance')
                ->label(__('admin.accounting.report_type.trial_balance'))
                ->visible(fn (): bool => $this->canViewReports())
                ->authorize(fn (): bool => $this->canViewReports())
                ->action(fn (): StreamedResponse => $this->streamTrialBalance()),
            Action::make('exportGeneralLedger')
                ->label(__('admin.accounting.report_type.general_ledger'))
                ->visible(fn (): bool => $this->canViewReports())
                ->authorize(fn (): bool => $this->canViewReports())
                ->action(fn (): StreamedResponse => $this->streamGeneralLedger()),
            Action::make('exportProfitAndLoss')
                ->label(__('admin.accounting.report_type.profit_and_loss'))
                ->visible(fn (): bool => $this->canViewReports())
                ->authorize(fn (): bool => $this->canViewReports())
                ->action(fn (): StreamedResponse => $this->streamProfitAndLoss()),
            Action::make('exportBalanceSheet')
                ->label(__('admin.accounting.report_type.balance_sheet'))
                ->visible(fn (): bool => $this->canViewReports())
                ->authorize(fn (): bool => $this->canViewReports())
                ->action(fn (): StreamedResponse => $this->streamBalanceSheet()),
            Action::make('exportPostingRegister')
                ->label(__('admin.accounting.report_type.posting_register'))
                ->visible(fn (): bool => $this->canViewReports())
                ->authorize(fn (): bool => $this->canViewReports())
                ->action(fn (): StreamedResponse => $this->streamPostingRegister()),
        ];
    }

    private function streamTrialBalance(): StreamedResponse
    {
        $this->authorizeReportAccess();

        [$from, $to] = [CarbonImmutable::parse($this->from), CarbonImmutable::parse($this->to)];
        $report = app(FinancialReportService::class)->trialBalance($from, $to);

        return $this->streamCsv('trial-balance.csv', function ($handle) use ($report, $from, $to): void {
            fputcsv($handle, [sprintf('Trial Balance — %s to %s', $from->toDateString(), $to->toDateString())], escape: '\\');
            fputcsv($handle, ['account_code', 'account_name', 'account_type', 'opening_balance', 'period_debit', 'period_credit', 'closing_balance'], escape: '\\');

            foreach ($report['rows'] as $row) {
                fputcsv($handle, [
                    $row['code'], $row['name'], $row['element'],
                    $row['openingBalance'], $row['periodDebit'], $row['periodCredit'], $row['closingBalance'],
                ], escape: '\\');
            }

            fputcsv($handle, ['', '', 'TOTAL', '', $report['totalDebit'], $report['totalCredit'], ''], escape: '\\');
            fputcsv($handle, [$report['foots']
                ? __('admin.accounting.reports.proof.balanced')
                : __('admin.accounting.reports.proof.out_of_balance', ['variance' => $report['variance']]),
            ], escape: '\\');
        });
    }

    private function streamGeneralLedger(): StreamedResponse
    {
        $this->authorizeReportAccess();

        [$from, $to] = [CarbonImmutable::parse($this->from), CarbonImmutable::parse($this->to)];
        $service = app(FinancialReportService::class);
        // The unpaginated export: one page holding every row (research §R8, C-8).
        $lines = $service->generalLedger($from, $to, $this->accountId, PHP_INT_MAX)->items();

        $scope = sprintf('General Ledger — %s to %s', $from->toDateString(), $to->toDateString());
        $account = $this->accountId !== null ? ChartAccount::query()->find($this->accountId) : null;

        if ($account instanceof ChartAccount) {
            $scope .= sprintf(' — Account %s %s', $account->code, $account->name);
        }

        return $this->streamCsv('general-ledger.csv', function ($handle) use ($lines, $scope): void {
            fputcsv($handle, [$scope], escape: '\\');
            fputcsv($handle, ['entry_number', 'entry_date', 'account_code', 'account_name', 'description', 'debit', 'credit', 'running_balance'], escape: '\\');

            foreach ($lines as $line) {
                fputcsv($handle, [
                    $line['entryNumber'], $line['entryDate'], $line['accountCode'], $line['accountName'],
                    $line['description'], $line['debit'], $line['credit'], $line['runningBalance'],
                ], escape: '\\');
            }
        });
    }

    private function streamProfitAndLoss(): StreamedResponse
    {
        $this->authorizeReportAccess();

        [$from, $to] = [CarbonImmutable::parse($this->from), CarbonImmutable::parse($this->to)];
        $report = app(FinancialReportService::class)->profitAndLoss($from, $to);

        return $this->streamCsv('profit-and-loss.csv', function ($handle) use ($report, $from, $to): void {
            fputcsv($handle, [sprintf('Profit and Loss — %s to %s', $from->toDateString(), $to->toDateString())], escape: '\\');
            fputcsv($handle, ['section', 'account_code', 'account_name', 'amount'], escape: '\\');

            foreach (['income' => 'Income', 'expense' => 'Expense'] as $key => $label) {
                foreach ($report['sections'][$key]['rows'] as $row) {
                    fputcsv($handle, [$label, $row['code'], $row['name'], $row['amount']], escape: '\\');
                }

                fputcsv($handle, ['SUBTOTAL '.$label, '', '', $report['sections'][$key]['subtotal']], escape: '\\');
            }

            fputcsv($handle, [$report['isLoss'] ? 'NET LOSS' : 'NET PROFIT', '', '', $report['netResult']], escape: '\\');
        });
    }

    private function streamBalanceSheet(): StreamedResponse
    {
        $this->authorizeReportAccess();

        $asOf = CarbonImmutable::parse($this->asOf);
        $report = app(FinancialReportService::class)->balanceSheet($asOf);

        return $this->streamCsv('balance-sheet.csv', function ($handle) use ($report, $asOf): void {
            fputcsv($handle, [sprintf('Balance Sheet — as of %s', $asOf->toDateString())], escape: '\\');
            fputcsv($handle, ['section', 'account_code', 'account_name', 'amount'], escape: '\\');

            $sections = [
                'asset' => ['Asset', 'SUBTOTAL Assets', 'totalAssets'],
                'liability' => ['Liability', 'SUBTOTAL Liabilities', 'totalLiabilities'],
                'equity' => ['Equity', 'SUBTOTAL Equity (posted)', 'totalPostedEquity'],
            ];

            foreach ($sections as $key => [$label, $subtotalLabel, $totalKey]) {
                foreach ($report['sections'][$key]['rows'] as $row) {
                    fputcsv($handle, [$label, $row['code'], $row['name'], $row['amount']], escape: '\\');
                }

                fputcsv($handle, [$subtotalLabel, '', '', $report[$totalKey]], escape: '\\');
            }

            fputcsv($handle, ['Equity', '', 'Accumulated Earnings (computed, not posted)', $report['accumulatedEarnings']], escape: '\\');

            fputcsv($handle, [$report['balances']
                ? __('admin.accounting.reports.proof.balanced')
                : __('admin.accounting.reports.proof.out_of_balance', ['variance' => $report['variance']]),
            ], escape: '\\');
        });
    }

    private function streamPostingRegister(): StreamedResponse
    {
        $this->authorizeReportAccess();

        [$from, $to] = [CarbonImmutable::parse($this->from), CarbonImmutable::parse($this->to)];
        $entries = app(FinancialReportService::class)->postingRegister($from, $to, PHP_INT_MAX)->items();

        return $this->streamCsv('posting-register.csv', function ($handle) use ($entries, $from, $to): void {
            fputcsv($handle, [sprintf('Posting Register — %s to %s', $from->toDateString(), $to->toDateString())], escape: '\\');
            fputcsv($handle, ['entry_number', 'entry_date', 'description', 'fiscal_period', 'posted_by', 'source', 'account_code', 'account_name', 'debit', 'credit'], escape: '\\');

            foreach ($entries as $entry) {
                foreach ($entry['lines'] as $line) {
                    fputcsv($handle, [
                        $entry['entryNumber'], $entry['entryDate'], $entry['description'],
                        $entry['fiscalPeriodName'], $entry['postedByName'], $entry['source']['label'] ?? '',
                        $line['accountCode'], $line['accountName'], $line['debit'], $line['credit'],
                    ], escape: '\\');
                }
            }
        });
    }

    /**
     * @param  callable(resource): void  $writer
     */
    private function streamCsv(string $filename, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($writer): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            $writer($handle);

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function authorizeReportAccess(): void
    {
        abort_unless($this->canViewReports(), 403);
    }

    private function canViewReports(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->can(AccountingPermission::ReportView->value);
    }
}
