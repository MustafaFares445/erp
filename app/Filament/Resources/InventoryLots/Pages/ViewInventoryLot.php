<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryLots\Pages;

use App\Filament\Resources\InventoryLots\InventoryLotResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewInventoryLot extends ViewRecord
{
    protected static string $resource = InventoryLotResource::class;
}
