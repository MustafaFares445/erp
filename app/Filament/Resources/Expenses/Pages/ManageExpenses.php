<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Models\User;
use App\Services\Accounting\AccountingDocumentService;
use App\Services\Accounting\ExpenseReceiptSynchronizer;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Illuminate\Support\Arr;
use LogicException;

final class ManageExpenses extends ManageRecords
{
    use RestrictsFileUploadsToSchemaComponents;

    protected static string $resource = ExpenseResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(function (
            array $data,
            AccountingDocumentService $documents,
            ExpenseReceiptSynchronizer $receipts,
        ): Expense {
            $actor = auth()->user();
            if (! $actor instanceof User) {
                throw new LogicException('An authenticated accounting user is required.');
            }

            $receipt = Arr::pull($data, 'receipt');
            $expense = $documents->recordExpense($actor, self::normalizeData($data));
            $receipts->sync($expense, is_string($receipt) ? $receipt : null);

            return $expense;
        })];
    }

    /** @return array<string, mixed> */
    private static function normalizeData(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $normalized = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
