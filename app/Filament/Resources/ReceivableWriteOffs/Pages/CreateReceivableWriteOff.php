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

        $reason = WriteOffReason::tryFrom((string) ($data['reason_category'] ?? ''));
        if (! $reason instanceof WriteOffReason) {
            throw new DomainException('A valid write-off reason category is required.');
        }

        return app(ReceivableWriteOffService::class)->record(
            new WriteOffData(
                customerId: (int) ($data['customer_id'] ?? 0),
                invoiceId: (int) ($data['invoice_id'] ?? 0),
                amountMinor: JournalEntryLine::toMinorUnits($data['amount'] ?? null),
                reasonCategory: $reason,
                reason: (string) ($data['reason'] ?? ''),
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
}
