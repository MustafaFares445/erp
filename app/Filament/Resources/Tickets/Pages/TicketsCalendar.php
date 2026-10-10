<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\Tickets\TicketResource;

final class TicketsCalendar extends ResourceCalendarPage
{
    protected static string $resource = TicketResource::class;

    protected static string $calendarKey = 'tickets';
}
