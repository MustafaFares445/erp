<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryReservations\Pages;

use App\Filament\Resources\InventoryReservations\Actions\InventoryReservationActions;
use App\Filament\Resources\InventoryReservations\InventoryReservationResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewInventoryReservation extends ViewRecord
{
    protected static string $resource = InventoryReservationResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            InventoryReservationActions::release(),
        ];
    }
}
