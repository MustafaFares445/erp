<?php

declare(strict_types=1);

namespace App\Services\Accounting\BankReconciliation;

use App\Enums\AccountingPermission;
use App\Enums\JournalEntryStatus;
use App\Enums\PaymentStatus;
use App\Enums\SupplierPaymentStatus;
use App\Models\BankReconciliationMatch;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class BankReconciliationService
{
    public function __construct(private JournalPostingService $journalPosting) {}

    public function match(User $actor, BankStatementLine $line, Model $target, float|string $amount, ?string $notes = null): BankReconciliationMatch
    {
        Gate::forUser($actor)->authorize(AccountingPermission::BankReconciliationManage->value);

        return DB::transaction(function () use ($actor, $line, $target, $amount, $notes): BankReconciliationMatch {
            /** @var BankStatementLine $locked */
            $locked = BankStatementLine::query()->with(['statement.paymentMethod', 'matches'])->lockForUpdate()->findOrFail($line->getKey());
            $statement = $locked->statement;

            if (! $statement instanceof BankStatement || $statement->status !== 'open') {
                throw new DomainException('Only an open bank statement can be reconciled.');
            }

            $amountMinor = JournalEntryLine::toMinorUnits($amount);
            if ($amountMinor <= 0 || $amountMinor > $locked->remainingMinor()) {
                throw new DomainException('Match amount exceeds the unreconciled bank statement amount.');
            }

            $targetKey = $target->getKey();
            if (! is_int($targetKey) && ! is_string($targetKey)) {
                throw new DomainException('Reconciliation target has an invalid identifier.');
            }

            /** @var Model $lockedTarget */
            $lockedTarget = $target->newQuery()->whereKey($targetKey)->lockForUpdate()->firstOrFail();
            $targetMinor = $this->targetAmountMinor($statement, $locked, $lockedTarget);
            $alreadyMatched = BankReconciliationMatch::query()
                ->where('matchable_type', $lockedTarget->getMorphClass())
                ->where('matchable_id', $lockedTarget->getKey())
                ->sum('amount');
            $availableMinor = $targetMinor - JournalEntryLine::toMinorUnits($alreadyMatched);

            if ($amountMinor > $availableMinor) {
                throw new DomainException('Match amount exceeds the remaining amount available on the selected record.');
            }

            /** @var BankReconciliationMatch $match */
            $match = $locked->matches()->create([
                'matchable_type' => $lockedTarget->getMorphClass(),
                'matchable_id' => $lockedTarget->getKey(),
                'amount' => number_format($amountMinor / 100, 2, '.', ''),
                'matched_by' => $actor->getKey(),
                'matched_at' => now(),
                'notes' => $notes,
            ]);

            $locked->load('matches');
            $locked->forceFill(['status' => $locked->remainingMinor() === 0 ? 'matched' : 'partial'])->save();

            return $match->refresh();
        });
    }

    public function postDifference(
        User $actor,
        BankStatementLine $line,
        int $differenceAccountId,
        ?string $description = null,
    ): JournalEntry {
        Gate::forUser($actor)->authorize(AccountingPermission::BankReconciliationManage->value);

        return DB::transaction(function () use ($actor, $line, $differenceAccountId, $description): JournalEntry {
            /** @var BankStatementLine $locked */
            $locked = BankStatementLine::query()->with(['statement.paymentMethod', 'matches'])->lockForUpdate()->findOrFail($line->getKey());
            $statement = $locked->statement;
            $paymentMethod = $statement?->paymentMethod;
            $bankAccount = $paymentMethod?->chartAccount;

            if (! $statement instanceof BankStatement || $statement->status !== 'open') {
                throw new DomainException('Only an open bank statement can receive a reconciliation difference.');
            }
            if (! $bankAccount instanceof ChartAccount || ! $bankAccount->is_active || ! $bankAccount->is_postable) {
                throw new DomainException('The statement payment method is not mapped to an active postable bank account.');
            }
            if ($differenceAccountId === $bankAccount->id) {
                throw new DomainException('The difference account must be different from the bank account.');
            }

            $remainingMinor = $locked->remainingMinor();
            if ($remainingMinor <= 0) {
                throw new DomainException('This bank statement line is already fully reconciled.');
            }

            $amount = number_format($remainingMinor / 100, 2, '.', '');
            $bankAccountId = $bankAccount->id;
            $lines = $locked->amountMinor() > 0
                ? [
                    ['chart_account_id' => $bankAccountId, 'debit' => $amount, 'credit' => 0, 'description' => $description],
                    ['chart_account_id' => $differenceAccountId, 'debit' => 0, 'credit' => $amount, 'description' => $description],
                ]
                : [
                    ['chart_account_id' => $differenceAccountId, 'debit' => $amount, 'credit' => 0, 'description' => $description],
                    ['chart_account_id' => $bankAccountId, 'debit' => 0, 'credit' => $amount, 'description' => $description],
                ];

            $entry = $this->journalPosting->postNew(
                $actor,
                $locked->transaction_date,
                $lines,
                $description ?? 'Bank reconciliation difference '.$statement->statement_number,
                $locked,
            );

            $this->match($actor, $locked, $entry, $amount, 'Reconciliation difference');

            return $entry;
        });
    }

    public function close(User $actor, BankStatement $statement): BankStatement
    {
        Gate::forUser($actor)->authorize(AccountingPermission::BankReconciliationManage->value);

        return DB::transaction(function () use ($actor, $statement): BankStatement {
            /** @var BankStatement $locked */
            $locked = BankStatement::query()->lockForUpdate()->findOrFail($statement->getKey());
            if ($locked->status !== 'open') {
                throw new DomainException('Only an open bank statement can be closed.');
            }

            $unreconciled = $locked->lines()->with('matches')->get()
                ->contains(fn (BankStatementLine $line): bool => $line->remainingMinor() !== 0);

            if ($unreconciled) {
                throw new DomainException('Every bank statement line must be fully reconciled before closing.');
            }

            $locked->forceFill([
                'status' => 'reconciled',
                'reconciled_at' => now(),
                'reconciled_by' => $actor->getKey(),
            ])->save();

            return $locked->refresh();
        });
    }

    private function targetAmountMinor(BankStatement $statement, BankStatementLine $line, Model $target): int
    {
        if ($target instanceof Payment) {
            if ($line->amountMinor() <= 0
                || $target->status !== PaymentStatus::Posted
                || $target->isReversed()
                || $target->payment_method_id !== $statement->payment_method_id
                || mb_strtoupper((string) $target->currency) !== mb_strtoupper((string) $statement->currency_code)) {
                throw new DomainException('The selected customer payment is not eligible for this bank statement line.');
            }

            return abs(JournalEntryLine::toMinorUnits($target->amount));
        }

        if ($target instanceof SupplierPayment) {
            if ($line->amountMinor() >= 0
                || $target->status !== SupplierPaymentStatus::Paid
                || $target->payment_method_id !== $statement->payment_method_id) {
                throw new DomainException('The selected supplier payment is not eligible for this bank statement line.');
            }

            return abs(JournalEntryLine::toMinorUnits($target->amount));
        }

        if ($target instanceof JournalEntry) {
            if ($target->status !== JournalEntryStatus::Posted) {
                throw new DomainException('Only posted journal entries can be matched to a bank statement.');
            }

            $paymentMethod = $statement->paymentMethod;
            $bankAccount = $paymentMethod?->chartAccount;
            if (! $bankAccount instanceof ChartAccount || ! $bankAccount->is_active || ! $bankAccount->is_postable) {
                throw new DomainException('The statement payment method is not mapped to an active postable bank account.');
            }

            $bankMovementMinor = $target->lines()
                ->where('chart_account_id', $bankAccount->id)
                ->get()
                ->sum(fn (JournalEntryLine $journalLine): int => $journalLine->signedMinorUnits());

            if ($bankMovementMinor === 0 || ($bankMovementMinor > 0) !== ($line->amountMinor() > 0)) {
                throw new DomainException('The journal entry does not contain a compatible movement on this bank account.');
            }

            return abs($bankMovementMinor);
        }

        throw new DomainException('Unsupported bank reconciliation match target.');
    }
}
