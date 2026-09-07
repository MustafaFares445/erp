<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Pages;

use App\Filament\Concerns\ExportsSalesDocuments;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Models\CreditNote;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;

final class ListCreditNotes extends ListRecords
{
    use ExportsSalesDocuments;

    protected static string $resource = CreditNoteResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            $this->salesDocumentExportAction(),
        ];
    }

    /** @return list<string> */
    private function salesDocumentExportHeadings(): array
    {
        return ['credit_note_number', 'customer', 'invoice_number', 'issue_date', 'grand_total', 'status'];
    }

    /** @return list<bool|float|int|string|null> */
    private function salesDocumentExportRow(Model $record): array
    {
        if (! $record instanceof CreditNote) {
            return [];
        }

        return [
            (string) $record->credit_note_number,
            $record->customer?->company_name,
            $record->invoice?->invoice_number,
            $record->issue_date->toDateString(),
            (string) $record->grand_total,
            $record->status->value,
        ];
    }

    private function salesDocumentExportFilename(): string
    {
        return 'credit-notes-'.now()->format('Ymd-His').'.csv';
    }

    private function salesDocumentExportLogName(): string
    {
        return 'sales.credit_note.exported';
    }
}
