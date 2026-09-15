<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shipments\Pages;

use App\Filament\Resources\Shipments\ShipmentResource;
use Filament\Resources\Pages\ListRecords;

final class ListShipments extends ListRecords
{
    protected static string $resource = ShipmentResource::class;
}
