<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Concerns\ExportsSalesDocuments;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Quotation;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;

final class ListQuotations extends ListRecords
{
    use ExportsSalesDocuments;

    protected static string $resource = QuotationResource::class;

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
        return ['quotation_number', 'customer', 'status', 'issue_date', 'expires_at', 'grand_total'];
    }

    /** @return list<bool|float|int|string|null> */
    private function salesDocumentExportRow(Model $record): array
    {
        if (! $record instanceof Quotation) {
            return [];
        }

        return [
            (string) $record->quotation_number,
            $record->customer?->company_name,
            $record->status->value,
            $record->issue_date->toDateString(),
            $record->expires_at?->toDateString(),
            (string) $record->grand_total,
        ];
    }

    private function salesDocumentExportFilename(): string
    {
        return 'quotations-'.now()->format('Ymd-His').'.csv';
    }

    private function salesDocumentExportLogName(): string
    {
        return 'sales.quotation.exported';
    }
}
