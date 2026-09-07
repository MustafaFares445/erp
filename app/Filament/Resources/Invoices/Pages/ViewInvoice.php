<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\Actions\InvoiceActions;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (Invoice $record): bool => $record->isDraft()),
            InvoiceActions::issue(),
            InvoiceActions::generatePdf(),
            InvoiceActions::send(),
            InvoiceActions::confirmReceipt(),
            InvoiceActions::recordPayment(),
            InvoiceActions::writeOff(),
            InvoiceActions::createCreditNote(),
        ];
    }
}
