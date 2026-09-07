<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Concerns\ExportsSalesDocuments;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;

final class ListInvoices extends ListRecords
{
    use ExportsSalesDocuments;

    protected static string $resource = InvoiceResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [$this->salesDocumentExportAction()];
    }

    /** @return list<string> */
    private function salesDocumentExportHeadings(): array
    {
        return ['invoice_number', 'customer', 'invoice_date', 'due_date', 'total_amount', 'amount_paid', 'credited_amount', 'status'];
    }

    /** @return list<bool|float|int|string|null> */
    private function salesDocumentExportRow(Model $record): array
    {
        if (! $record instanceof Invoice) {
            return [];
        }

        return [
            (string) $record->invoice_number,
            $record->customer?->company_name,
            $record->invoice_date->toDateString(),
            $record->due_date?->toDateString(),
            (string) $record->total_amount,
            (string) $record->amount_paid,
            (string) $record->credited_amount,
            $record->status->value,
        ];
    }

    private function salesDocumentExportFilename(): string
    {
        return 'invoices-'.now()->format('Ymd-His').'.csv';
    }

    private function salesDocumentExportLogName(): string
    {
        return 'sales.invoice.exported';
    }
}
