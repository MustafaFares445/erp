<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceRecords\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\ServiceRecords\ServiceRecordResource;

final class ServiceRecordsCalendar extends ResourceCalendarPage
{
    protected static string $resource = ServiceRecordResource::class;

    protected static string $calendarKey = 'service-records';
}
