<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Concerns\ExportsSalesDocuments;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Payment;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;

final class ListPayments extends ListRecords
{
    use ExportsSalesDocuments;

    protected static string $resource = PaymentResource::class;

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
        return ['payment_number', 'customer', 'payment_method', 'payment_date', 'amount', 'status', 'posted_at', 'reversed_at'];
    }

    /** @return list<bool|float|int|string|null> */
    private function salesDocumentExportRow(Model $record): array
    {
        if (! $record instanceof Payment) {
            return [];
        }

        return [
            (string) $record->payment_number,
            $record->customer?->company_name,
            $record->paymentMethod?->name,
            $record->payment_date->toDateString(),
            (string) $record->amount,
            $record->status->value,
            $record->posted_at?->toDateTimeString(),
            $record->reversed_at?->toDateTimeString(),
        ];
    }

    private function salesDocumentExportFilename(): string
    {
        return 'payments-'.now()->format('Ymd-His').'.csv';
    }

    private function salesDocumentExportLogName(): string
    {
        return 'sales.payment.exported';
    }
}
