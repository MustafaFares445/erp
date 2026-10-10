<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceAppointments\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\ServiceAppointments\ServiceAppointmentResource;

final class ServiceAppointmentsCalendar extends ResourceCalendarPage
{
    protected static string $resource = ServiceAppointmentResource::class;

    protected static string $calendarKey = 'service-appointments';
}
