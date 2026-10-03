<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryNotes\Pages;

use App\Filament\Resources\DeliveryNotes\Actions\DeliveryNoteActions;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\InventoryOperations\Actions\InventoryOperationActions;
use Filament\Resources\Pages\ViewRecord;

final class ViewDeliveryNote extends ViewRecord
{
    protected static string $resource = DeliveryNoteResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            InventoryOperationActions::generatePackingList(),
            DeliveryNoteActions::createInvoice(),
        ];
    }
}
