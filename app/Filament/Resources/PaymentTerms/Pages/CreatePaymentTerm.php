<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentTerms\Pages;

use App\Filament\Resources\PaymentTerms\PaymentTermResource;
use App\Services\Sales\PaymentTermService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreatePaymentTerm extends CreateRecord
{
    protected static string $resource = PaymentTermResource::class;

    /**
     * Delegates to {@see PaymentTermService} so the single-default invariant
     * (FR-009) cannot be skipped by using the dashboard.
     *
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        return app(PaymentTermService::class)->create($data);
    }
}
