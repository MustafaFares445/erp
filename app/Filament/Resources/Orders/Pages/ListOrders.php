<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Concerns\ExportsSalesDocuments;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;

final class ListOrders extends ListRecords
{
    use ExportsSalesDocuments;

    protected static string $resource = OrderResource::class;

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
        return ['order_number', 'customer', 'status', 'grand_total', 'payment_status', 'created_at'];
    }

    /** @return list<bool|float|int|string|null> */
    private function salesDocumentExportRow(Model $record): array
    {
        if (! $record instanceof Order) {
            return [];
        }

        return [
            (string) $record->order_number,
            $record->customer?->company_name,
            (string) $record->status,
            $record->grand_total !== null ? (string) $record->grand_total : null,
            $record->payment_status?->value,
            $record->created_at?->toDateTimeString(),
        ];
    }

    private function salesDocumentExportFilename(): string
    {
        return 'orders-'.now()->format('Ymd-His').'.csv';
    }

    private function salesDocumentExportLogName(): string
    {
        return 'sales.order.exported';
    }
}
