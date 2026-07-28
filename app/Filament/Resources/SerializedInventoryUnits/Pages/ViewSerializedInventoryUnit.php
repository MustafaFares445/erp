<?php

declare(strict_types=1);

namespace App\Filament\Resources\SerializedInventoryUnits\Pages;

use App\Filament\Resources\SerializedInventoryUnits\SerializedInventoryUnitResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewSerializedInventoryUnit extends ViewRecord
{
    protected static string $resource = SerializedInventoryUnitResource::class;
}
