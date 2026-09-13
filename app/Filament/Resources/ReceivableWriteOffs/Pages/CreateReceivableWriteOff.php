<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReceivableWriteOffs\Pages;

use App\Data\Accounting\WriteOffData;
use App\Enums\WriteOffReason;
use App\Filament\Resources\ReceivableWriteOffs\ReceivableWriteOffResource;
use App\Models\JournalEntryLine;
use App\Models\ReceivableWriteOff;
use App\Models\User;
use App\Services\Accounting\ReceivableWriteOffService;
use DomainException;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CreateReceivableWriteOff extends CreateRecord
{
    protected static string $resource = ReceivableWriteOffResource::class;

    /** @param array<string, mixed> $data */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            throw new LogicException('An authenticated accounting user is required.');
        }

        $reasonValue = $data['reason_category'] ?? null;
        $reason = WriteOffReason::tryFrom(is_string($reasonValue) ? $reasonValue : '');
        if (! $reason instanceof WriteOffReason) {
            throw new DomainException('A valid write-off reason category is required.');
        }

        return app(ReceivableWriteOffService::class)->record(
            new WriteOffData(
                customerId: self::integerValue($data['customer_id'] ?? null, 'customer'),
                invoiceId: self::integerValue($data['invoice_id'] ?? null, 'invoice'),
                amountMinor: JournalEntryLine::toMinorUnits($data['amount'] ?? null),
                reasonCategory: $reason,
                reason: self::stringValue($data['reason'] ?? null, 'reason'),
            ),
            $actor,
        );
    }

    #[\Override]
    protected function getRedirectUrl(): string
    {
        /** @var ReceivableWriteOff $record */
        $record = $this->record;

        return ReceivableWriteOffResource::getUrl('view', ['record' => $record]);
    }

    private static function integerValue(mixed $value, string $label): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new DomainException("A valid {$label} is required.");
    }

    private static function stringValue(mixed $value, string $label): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        throw new DomainException("A valid {$label} is required.");
    }
}
