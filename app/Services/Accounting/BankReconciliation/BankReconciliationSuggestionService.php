<?php

declare(strict_types=1);

namespace App\Services\Accounting\BankReconciliation;

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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class BankReconciliationSuggestionService
{
    /** @return list<array{target_type:string,target_id:int,label:string,amount:string,date:string,score:int,reasons:list<string>}> */
    public function suggest(BankStatementLine $line, int $limit = 10): array
    {
        $line->loadMissing('statement.paymentMethod.chartAccount');
        $statement = $line->statement;
        if (! $statement instanceof BankStatement || $statement->status !== 'open') {
            return [];
        }

        $bankAccount = $statement->paymentMethod?->chartAccount;
        if (! $bankAccount instanceof ChartAccount) {
            return [];
        }

        $candidates = $line->amountMinor() > 0
            ? $this->customerPaymentCandidates($statement, $line)
            : $this->supplierPaymentCandidates($statement, $line);

        $journalCandidates = $this->journalEntryCandidates($line, $bankAccount);

        return array_values($candidates
            ->concat($journalCandidates)
            ->sortByDesc('score')
            ->take(max(1, $limit))
            ->values()
            ->all());
    }

    /** @return Collection<int, array{target_type:string,target_id:int,label:string,amount:string,date:string,score:int,reasons:list<string>}> */
    private function customerPaymentCandidates(BankStatement $statement, BankStatementLine $line): Collection
    {
        return Payment::query()
            ->where('payment_method_id', $statement->payment_method_id)
            ->where('status', PaymentStatus::Posted->value)
            ->whereNull('reversed_at')
            ->where('currency', $statement->currency_code)
            ->whereBetween('payment_date', [
                $line->transaction_date->copy()->subDays(5)->toDateString(),
                $line->transaction_date->copy()->addDays(5)->toDateString(),
            ])
            ->limit(40)
            ->get()
            ->map(fn (Payment $payment): ?array => $this->candidate(
                $line,
                $payment,
                JournalEntryLine::toMinorUnits($payment->amount),
                (string) $payment->payment_date->toDateString(),
                (string) $payment->payment_number,
                $payment->external_reference,
            ))
            ->filter()
            ->values();
    }

    /** @return Collection<int, array{target_type:string,target_id:int,label:string,amount:string,date:string,score:int,reasons:list<string>}> */
    private function supplierPaymentCandidates(BankStatement $statement, BankStatementLine $line): Collection
    {
        return SupplierPayment::query()
            ->where('payment_method_id', $statement->payment_method_id)
            ->where('status', SupplierPaymentStatus::Paid->value)
            ->whereBetween('payment_date', [
                $line->transaction_date->copy()->subDays(5)->toDateString(),
                $line->transaction_date->copy()->addDays(5)->toDateString(),
            ])
            ->limit(40)
            ->get()
            ->map(fn (SupplierPayment $payment): ?array => $this->candidate(
                $line,
                $payment,
                JournalEntryLine::toMinorUnits($payment->amount),
                (string) $payment->payment_date->toDateString(),
                (string) $payment->supplier_payment_number,
                $payment->reference,
            ))
            ->filter()
            ->values();
    }

    /** @return Collection<int, array{target_type:string,target_id:int,label:string,amount:string,date:string,score:int,reasons:list<string>}> */
    private function journalEntryCandidates(BankStatementLine $line, ChartAccount $bankAccount): Collection
    {
        return JournalEntry::query()
            ->where('status', JournalEntryStatus::Posted->value)
            ->whereBetween('entry_date', [
                $line->transaction_date->copy()->subDays(5)->toDateString(),
                $line->transaction_date->copy()->addDays(5)->toDateString(),
            ])
            ->whereHas('lines', fn (Builder $query): Builder => $query->where('chart_account_id', $bankAccount->id))
            ->with('lines')
            ->limit(40)
            ->get()
            ->map(function (JournalEntry $entry) use ($line, $bankAccount): ?array {
                $movementMinor = $entry->lines
                    ->where('chart_account_id', $bankAccount->id)
                    ->sum(static fn (JournalEntryLine $journalLine): int => $journalLine->signedMinorUnits());

                if ($movementMinor === 0 || ($movementMinor > 0) !== ($line->amountMinor() > 0)) {
                    return null;
                }

                return $this->candidate(
                    $line,
                    $entry,
                    abs($movementMinor),
                    $entry->entry_date->toDateString(),
                    (string) $entry->entry_number,
                    $entry->description,
                );
            })
            ->filter()
            ->values();
    }

    /** @return array{target_type:string,target_id:int,label:string,amount:string,date:string,score:int,reasons:list<string>}|null */
    private function candidate(
        BankStatementLine $line,
        Model $target,
        int $targetMinor,
        string $date,
        string $label,
        ?string $reference,
    ): ?array {
        $targetKey = $target->getKey();
        if ((! is_int($targetKey) && ! is_string($targetKey)) || $targetMinor <= 0) {
            return null;
        }

        $alreadyMatched = BankReconciliationMatch::query()
            ->where('matchable_type', $target->getMorphClass())
            ->where('matchable_id', $targetKey)
            ->sum('amount');
        $availableMinor = $targetMinor - JournalEntryLine::toMinorUnits($alreadyMatched);
        if ($availableMinor <= 0) {
            return null;
        }

        $lineMinor = $line->remainingMinor();
        $suggestedMinor = min($lineMinor, $availableMinor);
        $score = 0;
        $reasons = [];
        if ($availableMinor === $lineMinor) {
            $score += 60;
            $reasons[] = 'Exact amount';
        } else {
            $difference = abs($availableMinor - $lineMinor);
            if ($lineMinor > 0 && ($difference / $lineMinor) <= 0.05) {
                $score += 20;
                $reasons[] = 'Amount within 5%';
            }
        }

        $days = (int) round(abs($line->transaction_date->diffInDays($date, false)));
        if ($days === 0) {
            $score += 20;
            $reasons[] = 'Same date';
        } elseif ($days <= 1) {
            $score += 15;
            $reasons[] = 'Date within 1 day';
        } elseif ($days <= 3) {
            $score += 10;
            $reasons[] = 'Date within 3 days';
        } else {
            $score += 5;
            $reasons[] = 'Date within 5 days';
        }

        $statementReference = self::normalizeReference($line->reference);
        $candidateReference = self::normalizeReference($reference);
        $candidateLabel = self::normalizeReference($label);

        if ($statementReference !== null && $candidateReference !== null && $statementReference === $candidateReference) {
            $score += 30;
            $reasons[] = 'Exact reference';
        } elseif ($statementReference !== null
            && (($candidateReference !== null && str_contains($candidateReference, $statementReference))
                || ($candidateLabel !== null && str_contains($candidateLabel, $statementReference)))) {
            $score += 15;
            $reasons[] = 'Reference match';
        }

        return [
            'target_type' => $target->getMorphClass(),
            'target_id' => (int) $targetKey,
            'label' => $label,
            'amount' => number_format($suggestedMinor / 100, 2, '.', ''),
            'date' => $date,
            'score' => min(100, $score),
            'reasons' => $reasons,
        ];
    }

    private static function normalizeReference(?string $value): ?string
    {
        if (! is_string($value) || mb_trim($value) === '') {
            return null;
        }

        return mb_strtolower((string) preg_replace('/\s+/', '', mb_trim($value)));
    }
}
