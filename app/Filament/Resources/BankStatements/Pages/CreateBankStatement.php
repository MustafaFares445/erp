<?php

declare(strict_types=1);

namespace App\Filament\Resources\BankStatements\Pages;

use App\Filament\Concerns\InteractsWithAccountingServices;
use App\Filament\Resources\BankStatements\BankStatementResource;
use App\Models\BankStatement;
use App\Models\User;
use App\Services\Accounting\BankReconciliation\BankStatementImportService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class CreateBankStatement extends CreateRecord
{
    use InteractsWithAccountingServices;

    protected static string $resource = BankStatementResource::class;

    /** @param array<string, mixed> $data */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $actor = self::accountingActor();
        if (! $actor instanceof User) {
            throw new Halt;
        }

        $rawRows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
        $rows = [];

        foreach ($rawRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rows[] = [
                'transaction_date' => self::stringFrom($row['transaction_date'] ?? null),
                'amount' => self::stringFrom($row['amount'] ?? null),
                'reference' => self::nullableStringFrom($row['reference'] ?? null),
                'counterparty' => self::nullableStringFrom($row['counterparty'] ?? null),
                'description' => self::nullableStringFrom($row['description'] ?? null),
            ];
        }

        return self::runAccountingOperation(fn (): BankStatement => app(BankStatementImportService::class)->import(
            $actor,
            [
                'payment_method_id' => self::integerFrom($data['payment_method_id'] ?? null),
                'currency_code' => self::stringFrom($data['currency_code'] ?? ''),
                'period_start' => self::stringFrom($data['period_start'] ?? null),
                'period_end' => self::stringFrom($data['period_end'] ?? null),
                'opening_balance' => self::stringFrom($data['opening_balance'] ?? 0),
                'closing_balance' => self::stringFrom($data['closing_balance'] ?? 0),
            ],
            $rows,
        ));
    }
}
