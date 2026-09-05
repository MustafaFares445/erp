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
use App\Services\Reconciliation\ReconciliationRunRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The period-close gate (WP-2.5, GAP-MW-18): every check the ledger's
 * "may this period close?" decision rests on.
 *
 * Every mandatory check delegates to whichever service already owns that
 * figure — {@see FinancialReportService::trialBalance()},
 * {@see AccountsReceivableService::reconciliation()},
 * {@see AccountsPayableService::summary()},
 * {@see TaxRegisterService::reconciliation()}, and a fresh
 * {@see InventoryLotReconciliationService} run recorded through
 * {@see ReconciliationRunRecorder} — never recomputing a figure a report
 * already owns by a different rule (XC-04).
 *
 * @see /ERP_REMEDIATION_PLAN.md WP-2.5
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

    /**
     * Runs every check for the period, persists each verdict, and returns
     * them all — mandatory and advisory alike.
     *
     * Always re-runs fresh rather than trusting a previously persisted
     * snapshot: the decision to close (or reopen) must rest on the figures as
     * they stand right now, not on whatever an accountant last clicked
     * "Run checklist" against.
     *
     * @return Collection<int, PeriodCloseResult>
     */
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

    /**
     * Runs the checklist and throws when a mandatory check fails — the
     * unconditional gate a caller uses when it has no override to offer.
     *
     * @return Collection<int, PeriodCloseResult>
     */
    public function assertCloseable(FiscalPeriod $period, ?User $actor = null): Collection
    {
        $results = $this->run($period, $actor);

        $failingMandatory = $results
            ->filter(fn (PeriodCloseResult $result): bool => $result->isMandatoryFailure())
            ->values();

        if ($failingMandatory->isNotEmpty()) {
            throw PeriodCloseBlocked::withFailingChecks(
                $failingMandatory->map(fn (PeriodCloseResult $result): PeriodCloseCheck => $result->check)->all()
            );
        }

        return $results;
    }

    /**
     * Every check's most recently persisted verdict for this period, keyed by
     * check value — a cheap read used to render the checklist without forcing
     * a fresh (and, for the stock check, side-effecting) run on every page
     * load.
     *
     * @return Collection<string, FiscalPeriodCloseCheck>
     */
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
     * Every check, mandatory or advisory, paired with its most recently
     * persisted verdict (or nulls when the checklist has never run) — the
     * shape the Filament layer renders and exports.
     *
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

    /**
     * Whether the most recently persisted results show any mandatory check
     * still failing — the cheap signal the Filament close action uses to
     * decide whether it should even be enabled for an actor without the
     * override permission.
     */
    public function hasUnresolvedMandatoryFailure(FiscalPeriod $period): bool
    {
        foreach ($this->statusRows($period) as $row) {
            if ($row['mandatory'] && $row['passed'] === false) {
                return true;
            }
        }

        return false;
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
                PeriodCloseCheck::NoDraftJournalEntriesInPeriod => $this->checkNoDraftJournalEntries($from, $to, $measuredAt),
                PeriodCloseCheck::NoUnpostedPaymentsInPeriod => $this->checkNoUnpostedPayments($from, $to, $measuredAt),
            };
        } catch (Throwable $exception) {
            // A check's owning figure could not be computed at all — for
            // example, the tax accounts a fresh install has not configured
            // yet. That is itself evidence the period cannot be confidently
            // reconciled, so it is recorded as a failed check rather than
            // letting an unrelated configuration gap crash the whole
            // close/reopen operation.
            return new PeriodCloseResult(
                check: $check,
                passed: false,
                detail: ['error' => $exception->getMessage()],
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
        // AccountsPayableService has no dedicated reconciliation() the way
        // AccountsReceivableService does; its summary()/aging() already carry
        // the same tie-out shape (outstanding vs. control account), so that
        // is reused here rather than diffing payableControlAccountMinor()
        // against a locally recomputed subledger total.
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

    /**
     * @param  Collection<int, PeriodCloseResult>  $results
     */
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
