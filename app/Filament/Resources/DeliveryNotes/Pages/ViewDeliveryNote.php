<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryNotes\Pages;

use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewDeliveryNote extends ViewRecord
{
    protected static string $resource = DeliveryNoteResource::class;
}
