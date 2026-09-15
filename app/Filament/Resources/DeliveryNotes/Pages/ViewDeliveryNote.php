<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryNotes\Pages;

use App\Enums\OperationStage;
use App\Filament\Concerns\InteractsWithSalesServices;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewDeliveryNote extends ViewRecord
{
    use InteractsWithSalesServices;

    protected static string $resource = DeliveryNoteResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create_invoice')
                ->label('Create invoice')
                ->icon(Heroicon::OutlinedDocumentPlus)
                ->color('primary')
                ->visible(fn (InventoryOperation $record): bool => $record->stage === OperationStage::Done
                    && ! $record->isInvoiced()
                    && (self::salesActor()?->can('create', Invoice::class) ?? false))
                ->authorize(fn (InventoryOperation $record): bool => self::salesActor()?->can('create', Invoice::class) ?? false)
                ->action(function (InventoryOperation $record): void {
                    $actor = self::salesActor();

                    if (! $actor instanceof User) {
                        return;
                    }

                    $invoice = self::runSalesOperation(
                        fn (): Invoice => app(InvoiceService::class)->createFromDelivery($actor, $record),
                    );

                    Notification::make()->success()->title('Draft invoice created from delivery.')->send();
                    $this->redirect(InvoiceResource::getUrl('view', ['record' => $invoice]));
                }),
        ];
    }
}
