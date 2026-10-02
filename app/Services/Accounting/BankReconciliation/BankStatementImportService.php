<?php

declare(strict_types=1);

namespace App\Services\Accounting\BankReconciliation;

use App\Enums\AccountingPermission;
use App\Models\BankStatement;
use App\Models\ChartAccount;
use App\Models\JournalEntryLine;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Settings\CurrencyCatalogService;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class BankStatementImportService
{
    public function __construct(
        private BankStatementNumberGenerator $numbers,
        private CurrencyCatalogService $currencies,
    ) {}

    /**
     * @param  array{payment_method_id:int,currency_code:string,period_start:string,period_end:string,opening_balance:float|string,closing_balance:float|string}  $attributes
     * @param  list<array{transaction_date:string,amount:float|string,reference?:string|null,counterparty?:string|null,description?:string|null}>  $rows
     */
    public function import(User $actor, array $attributes, array $rows): BankStatement
    {
        Gate::forUser($actor)->authorize(AccountingPermission::BankReconciliationManage->value);

        if ($rows === []) {
            throw new DomainException('A bank statement requires at least one transaction line.');
        }

        return DB::transaction(function () use ($actor, $attributes, $rows): BankStatement {
            /** @var PaymentMethod $paymentMethod */
            $paymentMethod = PaymentMethod::query()->with('chartAccount')->findOrFail($attributes['payment_method_id']);
            $chartAccount = $paymentMethod->chartAccount;

            if (! $paymentMethod->is_active
                || ! $chartAccount instanceof ChartAccount
                || ! $chartAccount->is_active
                || ! $chartAccount->is_postable) {
                throw new DomainException('Bank reconciliation requires an active payment method mapped to an active postable chart account.');
            }

            $periodStart = Carbon::parse($attributes['period_start'])->startOfDay();
            $periodEnd = Carbon::parse($attributes['period_end'])->startOfDay();
            if ($periodEnd->lt($periodStart)) {
                throw new DomainException('Bank statement period end cannot be before its start date.');
            }

            $currencyCode = $this->currencies->normalizeBase($attributes['currency_code'], 'currency_code');
            $normalizedRows = $this->normalizeRows($rows, $periodStart, $periodEnd);
            $openingBalance = number_format((float) $attributes['opening_balance'], 2, '.', '');
            $closingBalance = number_format((float) $attributes['closing_balance'], 2, '.', '');
            $movementMinor = array_sum(array_map(
                static fn (array $row): int => JournalEntryLine::toMinorUnits($row['amount']),
                $normalizedRows,
            ));
            $expectedClosingMinor = JournalEntryLine::toMinorUnits($openingBalance) + $movementMinor;
            if ($expectedClosingMinor !== JournalEntryLine::toMinorUnits($closingBalance)) {
                throw new DomainException('Bank statement opening balance plus transactions must equal its closing balance.');
            }

            $canonicalRows = $normalizedRows;
            usort($canonicalRows, static fn (array $left, array $right): int => strcmp(
                implode('|', $left),
                implode('|', $right),
            ));

            $importHash = hash('sha256', json_encode([
                'payment_method_id' => $paymentMethod->getKey(),
                'currency_code' => $currencyCode,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'opening_balance' => $openingBalance,
                'closing_balance' => $closingBalance,
                'rows' => $canonicalRows,
            ], JSON_THROW_ON_ERROR));

            $existing = BankStatement::query()->where('import_hash', $importHash)->first();
            if ($existing instanceof BankStatement) {
                return $existing->load('lines');
            }

            $statement = new BankStatement([
                'payment_method_id' => $paymentMethod->getKey(),
                'currency_code' => $currencyCode,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'opening_balance' => $openingBalance,
                'closing_balance' => $closingBalance,
            ]);
            $statement->forceFill([
                'statement_number' => $this->numbers->next(),
                'import_hash' => $importHash,
                'status' => 'open',
                'imported_at' => now(),
                'imported_by' => $actor->getKey(),
            ])->save();

            foreach ($normalizedRows as $index => $normalized) {
                $hash = hash('sha256', implode('|', [
                    $normalized['transaction_date'],
                    $normalized['amount'],
                    $normalized['reference'] ?? '',
                    $normalized['counterparty'] ?? '',
                    $normalized['description'] ?? '',
                ]));

                $statement->lines()->create([
                    ...$normalized,
                    'sequence' => $index + 1,
                    'line_hash' => $hash,
                    'status' => 'unmatched',
                ]);
            }

            return $statement->refresh()->load('lines');
        });
    }

    /**
     * @param  list<array{transaction_date:string,amount:float|string,reference?:string|null,counterparty?:string|null,description?:string|null}>  $rows
     * @return list<array{transaction_date:string,amount:string,reference:string|null,counterparty:string|null,description:string|null}>
     */
    private function normalizeRows(array $rows, Carbon $periodStart, Carbon $periodEnd): array
    {
        $normalizedRows = [];

        foreach ($rows as $row) {
            $date = Carbon::parse($row['transaction_date'])->startOfDay();
            if ($date->lt($periodStart) || $date->gt($periodEnd)) {
                throw new DomainException('Every bank statement line date must fall inside the statement period.');
            }

            $amount = number_format((float) $row['amount'], 2, '.', '');
            if (JournalEntryLine::toMinorUnits($amount) === 0) {
                throw new DomainException('Bank statement line amount cannot be zero.');
            }

            $normalizedRows[] = [
                'transaction_date' => $date->toDateString(),
                'amount' => $amount,
                'reference' => self::nullableTrim($row['reference'] ?? null),
                'counterparty' => self::nullableTrim($row['counterparty'] ?? null),
                'description' => self::nullableTrim($row['description'] ?? null),
            ];
        }

        return $normalizedRows;
    }

    private static function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = mb_trim($value);

        return $value === '' ? null : $value;
    }
}
