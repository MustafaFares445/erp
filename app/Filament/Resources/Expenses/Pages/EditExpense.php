<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Services\Accounting\ExpenseReceiptSynchronizer;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

final class EditExpense extends EditRecord
{
    protected static string $resource = ExpenseResource::class;

    /** @param array<string, mixed> $data */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Expense) {
            return parent::handleRecordUpdate($record, $data);
        }

        $receipt = Arr::pull($data, 'receipt');
        $record->update($this->normalizeData($data));

        app(ExpenseReceiptSynchronizer::class)->sync(
            $record,
            is_string($receipt) ? $receipt : null,
        );

        return $record;
    }

    /** @return array<string, mixed> */
    private function normalizeData(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $normalized = [];
        foreach ($data as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }
}
