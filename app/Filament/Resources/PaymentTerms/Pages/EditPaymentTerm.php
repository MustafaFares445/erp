<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentTerms\Pages;

use App\Filament\Resources\PaymentTerms\PaymentTermResource;
use App\Models\PaymentTerm;
use App\Services\Sales\PaymentTermService;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class EditPaymentTerm extends EditRecord
{
    protected static string $resource = PaymentTermResource::class;

    /**
     * Delegates to {@see PaymentTermService} so the single-default invariant
     * (FR-009) cannot be skipped by using the dashboard.
     *
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof PaymentTerm) {
            throw new Halt;
        }

        return app(PaymentTermService::class)->update($record, $data);
    }
}
