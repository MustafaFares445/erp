<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryNotes\Actions;

use App\Enums\OperationStage;
use App\Filament\Concerns\InteractsWithSalesServices;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Delivery note operations shared by the detail page and the list rows so the
 * invoicing behaviour cannot drift between them.
 */
final class DeliveryNoteActions
{
    use InteractsWithSalesServices;

    public static function createInvoice(): Action
    {
        return Action::make('create_invoice')
            ->label(__('Create invoice'))
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->color('primary')
            ->visible(fn (InventoryOperation $record): bool => $record->stage === OperationStage::Done
                && ! $record->isInvoiced()
                && (self::salesActor()?->can('create', Invoice::class) ?? false))
            ->authorize(fn (InventoryOperation $record): bool => self::salesActor()?->can('create', Invoice::class) ?? false)
            ->successRedirectUrl(fn (InventoryOperation $record): ?string => ($invoice = $record->unsetRelation('invoiceDeliveryLink')->relatedInvoice()) instanceof Invoice
                ? InvoiceResource::getUrl('view', ['record' => $invoice])
                : null)
            ->action(function (InventoryOperation $record): void {
                /** @var User $actor */
                $actor = self::salesActor();

                self::runSalesOperation(
                    fn (): Invoice => app(InvoiceService::class)->createFromDelivery($actor, $record),
                );

                Notification::make()->success()->title(__('Draft invoice created from delivery.'))->send();
            });
    }
}
