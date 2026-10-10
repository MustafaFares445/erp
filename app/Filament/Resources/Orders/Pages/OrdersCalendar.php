<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\Orders\OrderResource;

final class OrdersCalendar extends ResourceCalendarPage
{
    protected static string $resource = OrderResource::class;

    protected static string $calendarKey = 'orders';
}
