<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryLots\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\InventoryLots\InventoryLotResource;

final class InventoryLotsCalendar extends ResourceCalendarPage
{
    protected static string $resource = InventoryLotResource::class;

    protected static string $calendarKey = 'inventory-lots';
}
