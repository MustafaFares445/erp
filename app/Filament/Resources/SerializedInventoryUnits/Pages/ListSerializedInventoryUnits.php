<?php

declare(strict_types=1);

namespace App\Filament\Resources\SerializedInventoryUnits\Pages;

use App\Filament\Resources\SerializedInventoryUnits\SerializedInventoryUnitResource;
use Filament\Resources\Pages\ListRecords;

final class ListSerializedInventoryUnits extends ListRecords
{
    protected static string $resource = SerializedInventoryUnitResource::class;
}
