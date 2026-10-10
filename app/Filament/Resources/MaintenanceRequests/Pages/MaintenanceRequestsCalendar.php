<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;

final class MaintenanceRequestsCalendar extends ResourceCalendarPage
{
    protected static string $resource = MaintenanceRequestResource::class;

    protected static string $calendarKey = 'maintenance-requests';
}
