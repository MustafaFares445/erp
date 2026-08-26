<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bills\Pages;

use App\Filament\Resources\Bills\BillResource;
use App\Models\Bill;
use App\Models\User;
use App\Services\Accounting\AccountingDocumentService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Arr;
use LogicException;

final class ManageBills extends ManageRecords
{
    protected static string $resource = BillResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(function (array $data, AccountingDocumentService $documents): Bill {
            $actor = auth()->user();
            if (! $actor instanceof User) {
                throw new LogicException('An authenticated accounting user is required.');
            }

            $lines = Arr::pull($data, 'lines', []);
            $normalizedLines = [];
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    if (is_array($line)) {
                        $normalizedLines[] = self::normalizeData($line);
                    }
                }
            }

            return $documents->recordBill($actor, self::normalizeData($data), $normalizedLines);
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
