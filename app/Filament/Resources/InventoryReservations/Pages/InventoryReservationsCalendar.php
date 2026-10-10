<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryReservations\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\InventoryReservations\InventoryReservationResource;

final class InventoryReservationsCalendar extends ResourceCalendarPage
{
    protected static string $resource = InventoryReservationResource::class;

    protected static string $calendarKey = 'inventory-reservations';
}
