<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\Actions\InvoiceActions;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    /** @return array<int, Action> */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            // Primary, state-driven progress actions — each carries its own visibility.
            InvoiceActions::issue(),
            InvoiceActions::recordPayment(),
            InvoiceActions::retryDepositApplication(),
            EditAction::make()->visible(fn (Invoice $record): bool => $record->isDraft()),
            InvoiceActions::generatePdf(),
            InvoiceActions::send(),
            // Exception actions.
            InvoiceActions::writeOff(),
            InvoiceActions::createCreditNote(),
        ];
    }
}
