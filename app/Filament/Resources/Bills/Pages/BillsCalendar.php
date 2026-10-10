<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bills\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\Bills\BillResource;

final class BillsCalendar extends ResourceCalendarPage
{
    protected static string $resource = BillResource::class;

    protected static string $calendarKey = 'bills';
}
