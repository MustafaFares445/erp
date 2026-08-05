<?php

declare(strict_types=1);

namespace App\Filament\Resources\ShipmentAttachments\Pages;

use App\Filament\Resources\ShipmentAttachments\ShipmentAttachmentResource;
use Filament\Resources\Pages\ListRecords;

final class ListShipmentAttachments extends ListRecords
{
    protected static string $resource = ShipmentAttachmentResource::class;

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.shipment_attachments');
    }
}
