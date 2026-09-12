<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Data\Accounting\PeriodCloseResult;
use App\Enums\JournalEntryStatus;
use App\Enums\PeriodCloseCheck;
use App\Enums\ReconciliationScope;
use App\Models\FiscalPeriod;
use App\Models\FiscalPeriodCloseCheck;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\ReconciliationRun;
use App\Models\User;
use App\Services\Accounting\Exceptions\PeriodCloseBlocked;
use App\Services\Inventory\InventoryLotReconciliationService;
use App\Services\Inventory\InventoryValuationService;
use App\Services\Reconciliation\ReconciliationRunRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The period-close gate (WP-2.5, GAP-MW-18): every check the ledger's
 * "may this period close?" decision rests on.
 *
 * Mandatory checks delegate to the service that owns each figure. WP-4.6 adds
 * the inventory valuation/control-account tie-out alongside the physical stock
 * reconciliation so quantity correctness and financial correctness are both
 * explicit close conditions.
 */
final readonly class PeriodCloseChecklistService
{
    public function __construct(
        private FinancialReportService $financialReports,
        private AccountsReceivableService $receivables,
        private AccountsPayableService $payables,
        private TaxRegisterService $taxRegister,
        private InventoryLotReconciliationService $inventoryReconciliation,
        private ReconciliationRunRecorder $reconciliationRecorder,
    ) {}

    /** @return Collection<int, PeriodCloseResult> */
    public function run(FiscalPeriod $period, ?User $actor = null): Collection
    {
        $from = CarbonImmutable::parse($period->starts_at);
        $to = CarbonImmutable::parse($period->ends_at);
        $measuredAt = CarbonImmutable::now();

        $results = collect(PeriodCloseCheck::cases())
            ->map(fn (PeriodCloseCheck $check): PeriodCloseResult => $this->evaluate($check, $from, $to, $measuredAt, $actor))
            ->values();

        $this->persist($period, $results);

        return $results;
    }

    /** @return Collection<int, PeriodCloseResult> */
    public function assertCloseable(FiscalPeriod $period, ?User $actor = null): Collection
    {
        $results = $this->run($period, $actor);
        $failingMandatory = $results
            ->filter(fn (PeriodCloseResult $result): bool => $result->isMandatoryFailure())
            ->values();

        if ($failingMandatory->isNotEmpty()) {
            throw PeriodCloseBlocked::withFailingChecks(
                $failingMandatory->map(fn (PeriodCloseResult $result): PeriodCloseCheck => $result->check)->values()->all()
            );
        }

        return $results;
    }

    /** @return Collection<string, FiscalPeriodCloseCheck> */
    public function latestPersisted(FiscalPeriod $period): Collection
    {
        return FiscalPeriodCloseCheck::query()
            ->where('fiscal_period_id', $period->getKey())
            ->orderByDesc('measured_at')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (FiscalPeriodCloseCheck $check): string => $check->check_key->value)
            ->keyBy(fn (FiscalPeriodCloseCheck $check): string => $check->check_key->value);
    }

    /**
     * @return list<array{check: PeriodCloseCheck, mandatory: bool, passed: ?bool, measured_at: ?CarbonImmutable, detail: ?array<string, mixed>, reconciliation_run_id: ?int}>
     */
    public function statusRows(FiscalPeriod $period): array
    {
        $latest = $this->latestPersisted($period);

        return array_map(function (PeriodCloseCheck $check) use ($latest): array {
            $row = $latest->get($check->value);

            return [
                'check' => $check,
                'mandatory' => $check->isMandatory(),
                'passed' => $row?->passed,
                'measured_at' => $row?->measured_at !== null ? CarbonImmutable::instance($row->measured_at) : null,
                'detail' => $row?->detail,
                'reconciliation_run_id' => $row?->reconciliation_run_id,
            ];
        }, PeriodCloseCheck::cases());
    }

    public function hasUnresolvedMandatoryFailure(FiscalPeriod $period): bool
    {
        return array_any($this->statusRows($period), fn (array $row): bool => $row['mandatory'] && $row['passed'] === false);
    }

    private function evaluate(
        PeriodCloseCheck $check,
        CarbonImmutable $from,
        CarbonImmutable $to,
        CarbonImmutable $measuredAt,
        ?User $actor,
    ): PeriodCloseResult {
        try {
            return match ($check) {
                PeriodCloseCheck::TrialBalanceBalances => $this->checkTrialBalance($from, $to, $measuredAt),
                PeriodCloseCheck::ReceivablesAgreeToControlAccount => $this->checkReceivables($to, $measuredAt),
                PeriodCloseCheck::PayablesAgreeToControlAccount => $this->checkPayables($to, $measuredAt),
                PeriodCloseCheck::TaxRegisterAgreesToTaxAccounts => $this->checkTaxRegister($from, $to, $measuredAt),
                PeriodCloseCheck::StockLedgerReconciles => $this->checkStockLedger($measuredAt, $actor),
                PeriodCloseCheck::InventoryAgreesToControlAccount => $this->checkInventoryValuation($to, $measuredAt),
                PeriodCloseCheck::NoDraftJournalEntriesInPeriod => $this->checkNoDraftJournalEntries($from, $to, $measuredAt),
                PeriodCloseCheck::NoUnpostedPaymentsInPeriod => $this->checkNoUnpostedPayments($from, $to, $measuredAt),
            };
        } catch (Throwable $throwable) {
            return new PeriodCloseResult(
                check: $check,
                passed: false,
                detail: ['error' => $throwable->getMessage()],
                measuredAt: $measuredAt,
            );
        }
    }

    private function checkTrialBalance(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $measuredAt): PeriodCloseResult
    {
        $trialBalance = $this->financialReports->trialBalance($from, $to);

        return new PeriodCloseResult(
            check: PeriodCloseCheck::TrialBalanceBalances,
            passed: $trialBalance['foots'],
            detail: [
                'total_debit' => $trialBalance['totalDebit'],
                'total_credit' => $trialBalance['totalCredit'],
                'variance' => $trialBalance['variance'],
            ],
            measuredAt: $measuredAt,
        );
    }

    private function checkReceivables(CarbonImmutable $to, CarbonImmutable $measuredAt): PeriodCloseResult
    {
        $reconciliation = $this->receivables->reconciliation($to);

        return new PeriodCloseResult(
            check: PeriodCloseCheck::ReceivablesAgreeToControlAccount,
            passed: $reconciliation['is_reconciled'],
            detail: [
                'subledger_minor' => $reconciliation['subledger_minor'],
                'control_account_minor' => $reconciliation['control_account_minor'],
                'difference_minor' => $reconciliation['difference_minor'],
            ],
            measuredAt: $measuredAt,
        );
    }

    private function checkPayables(CarbonImmutable $to, CarbonImmutable $measuredAt): PeriodCloseResult
    {
        $summary = $this->payables->summary($to);

        return new PeriodCloseResult(
            check: PeriodCloseCheck::PayablesAgreeToControlAccount,
            passed: $summary['is_reconciled'],
            detail: [
                'subledger_minor' => $summary['outstanding_minor'],
                'control_account_minor' => $summary['control_account_minor'],
                'difference_minor' => $summary['tie_out_difference_minor'],
            ],
            measuredAt: $measuredAt,
        );
    }

    private function checkTaxRegister(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $measuredAt): PeriodCloseResult
    {
        $reconciliation = $this->taxRegister->reconciliation($from, $to);

        $passed = $this->isZeroAmount($reconciliation['deferred']['difference'])
            && $this->isZeroAmount($reconciliation['payable']['difference'])
            && $this->isZeroAmount($reconciliation['input']['difference']);

        return new PeriodCloseResult(
            check: PeriodCloseCheck::TaxRegisterAgreesToTaxAccounts,
            passed: $passed,
            detail: $reconciliation,
            measuredAt: $measuredAt,
        );
    }

    private function checkStockLedger(CarbonImmutable $measuredAt, ?User $actor): PeriodCloseResult
    {
        $inspection = $this->inventoryReconciliation->inspectDetailed();

        $this->reconciliationRecorder->record(
            ReconciliationScope::InventoryLots,
            $inspection['invariants'],
            'period_close',
            $actor,
        );

        $runId = ReconciliationRun::query()
            ->where('scope', ReconciliationScope::InventoryLots)
            ->where('trigger_source', 'period_close')
            ->orderByDesc('id')
            ->value('id');

        $errors = $inspection['report']['errors'];

        return new PeriodCloseResult(
            check: PeriodCloseCheck::StockLedgerReconciles,
            passed: $errors === [],
            detail: [
                'checked_lot_balances' => $inspection['report']['checked_lot_balances'],
                'checked_aggregate_balances' => $inspection['report']['checked_aggregate_balances'],
                'checked_reservation_grains' => $inspection['report']['checked_reservation_grains'],
                'checked_serial_grains' => $inspection['report']['checked_serial_grains'],
                'checked_return_lines' => $inspection['report']['checked_return_lines'],
                'checked_movements' => $inspection['report']['checked_movements'],
                'error_count' => count($errors),
                'errors' => array_slice($errors, 0, 20),
            ],
            measuredAt: $measuredAt,
            reconciliationRunId: is_numeric($runId) ? (int) $runId : null,
        );
    }

    private function checkInventoryValuation(CarbonImmutable $to, CarbonImmutable $measuredAt): PeriodCloseResult
    {
        // Resolved lazily rather than constructor-injected: InventoryValuationService posts
        // journal entries through JournalPostingService, which resolves FiscalPeriodService,
        // which resolves this checklist service back — an eager dependency here would be a
        // circular container resolution on every inventory movement.
        $valuation = app(InventoryValuationService::class);

        // Valuation is an opt-in posting feature (see the "IfConfigured" postings it guards):
        // a business that has not configured an inventory asset account has nothing to
        // reconcile, so this check does not block close for it.
        if (! $valuation->isConfigured()) {
            return new PeriodCloseResult(
                check: PeriodCloseCheck::InventoryAgreesToControlAccount,
                passed: true,
                detail: ['skipped' => 'Inventory valuation is not configured.'],
                measuredAt: $measuredAt,
            );
        }

        $reconciliation = $valuation->reconciliation($to);

        return new PeriodCloseResult(
            check: PeriodCloseCheck::InventoryAgreesToControlAccount,
            passed: $reconciliation['is_reconciled'],
            detail: $reconciliation,
            measuredAt: $measuredAt,
        );
    }

    private function checkNoDraftJournalEntries(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $measuredAt): PeriodCloseResult
    {
        $query = JournalEntry::query()
            ->where('status', JournalEntryStatus::Draft->value)
            ->whereDate('entry_date', '>=', $from->toDateString())
            ->whereDate('entry_date', '<=', $to->toDateString());

        $count = $query->count();
        $entryNumbers = (clone $query)->orderBy('entry_date')->limit(20)->pluck('entry_number')->all();

        return new PeriodCloseResult(
            check: PeriodCloseCheck::NoDraftJournalEntriesInPeriod,
            passed: $count === 0,
            detail: ['count' => $count, 'entry_numbers' => $entryNumbers],
            measuredAt: $measuredAt,
        );
    }

    private function checkNoUnpostedPayments(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $measuredAt): PeriodCloseResult
    {
        $query = Payment::query()
            ->whereNull('posted_at')
            ->whereDate('payment_date', '>=', $from->toDateString())
            ->whereDate('payment_date', '<=', $to->toDateString());

        $count = $query->count();
        $paymentNumbers = (clone $query)->orderBy('payment_date')->limit(20)->pluck('payment_number')->all();

        return new PeriodCloseResult(
            check: PeriodCloseCheck::NoUnpostedPaymentsInPeriod,
            passed: $count === 0,
            detail: ['count' => $count, 'payment_numbers' => $paymentNumbers],
            measuredAt: $measuredAt,
        );
    }

    private function isZeroAmount(string $decimal): bool
    {
        return bccomp($decimal, '0', 2) === 0;
    }

    /** @param Collection<int, PeriodCloseResult> $results */
    private function persist(FiscalPeriod $period, Collection $results): void
    {
        DB::transaction(function () use ($period, $results): void {
            foreach ($results as $result) {
                FiscalPeriodCloseCheck::query()->create([
                    'fiscal_period_id' => $period->getKey(),
                    'check_key' => $result->check,
                    'passed' => $result->passed,
                    'detail' => $result->detail === [] ? null : $result->detail,
                    'measured_at' => $result->measuredAt,
                    'reconciliation_run_id' => $result->reconciliationRunId,
                ]);
            }
        });
    }
}
