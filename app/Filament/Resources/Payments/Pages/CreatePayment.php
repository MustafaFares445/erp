<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\PaymentService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use LogicException;

final class CreatePayment extends CreateRecord
{
    protected static string $resource = PaymentResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated sales user is required.');
        }

        $proof = $data['payment_proof'] ?? null;
        unset($data['payment_proof']);

        $proofPath = is_string($proof) && $proof !== ''
            ? Storage::disk('local')->path($proof)
            : null;

        return app(PaymentService::class)->createDraft($actor, $data, $proofPath);
    }

    #[\Override]
    protected function getRedirectUrl(): string
    {
        $parameters = ['record' => $this->record];
        $invoiceId = request()->integer('invoice_id');

        if ($invoiceId > 0) {
            $parameters['invoice_id'] = $invoiceId;
        }

        return PaymentResource::getUrl('view', $parameters);
    }
}
