<?php

declare(strict_types=1);

namespace App\Filament\Resources\SerializedInventoryUnits\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\SerializedInventoryUnits\SerializedInventoryUnitResource;

final class SerializedInventoryUnitsCalendar extends ResourceCalendarPage
{
    protected static string $resource = SerializedInventoryUnitResource::class;

    protected static string $calendarKey = 'serialized-inventory-units';
}
