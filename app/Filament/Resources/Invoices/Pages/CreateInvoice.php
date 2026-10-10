<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Filament\Resources\Pages\CreateRecord;

final class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        /** @var list<array<string, mixed>> $lines */
        $lines = [];
        $rawLines = $data['lines'] ?? [];
        if (is_array($rawLines)) {
            foreach ($rawLines as $rawLine) {
                if (! is_array($rawLine)) {
                    continue;
                }

                $line = [];
                foreach ($rawLine as $key => $value) {
                    if (is_string($key)) {
                        $line[$key] = $value;
                    }
                }
                $lines[] = $line;
            }
        }

        try {
            return app(InvoiceService::class)->createStandalone($actor, [
                'customer_id' => $data['customer_id'] ?? null,
                'payment_term_id' => $data['payment_term_id'] ?? null,
                'invoice_date' => $data['invoice_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'description' => $data['description'] ?? null,
            ], $lines);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'data.lines' => $exception->getMessage(),
            ]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return InvoiceResource::getUrl('view', ['record' => $this->record]);
    }
}
