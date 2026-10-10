<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Pages;

use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Models\Invoice;
use Illuminate\Validation\ValidationException;
use Filament\Resources\Pages\CreateRecord;

final class CreateCreditNote extends CreateRecord
{
    protected static string $resource = CreditNoteResource::class;

    /** @param array<string, mixed> $data
     *  @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (filled($data['invoice_id'] ?? null)) {
            $invoice = Invoice::query()
                ->whereKey($data['invoice_id'])
                ->where('customer_id', $data['customer_id'] ?? null)
                ->whereNotNull('issued_at')
                ->first();

            if ($invoice === null) {
                throw ValidationException::withMessages([
                    'data.invoice_id' => __('Select an issued invoice belonging to the chosen customer.'),
                ]);
            }
        }

        return $data;
    }
}
