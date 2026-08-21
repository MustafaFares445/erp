<?php

declare(strict_types=1);

namespace App\Filament\Resources\ShipmentAttachments\Pages;

use App\Filament\Resources\ShipmentAttachments\ShipmentAttachmentResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewShipmentAttachment extends ViewRecord
{
    protected static string $resource = ShipmentAttachmentResource::class;
}
