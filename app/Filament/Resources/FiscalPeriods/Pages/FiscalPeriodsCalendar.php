<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalPeriods\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\FiscalPeriods\FiscalPeriodResource;

final class FiscalPeriodsCalendar extends ResourceCalendarPage
{
    protected static string $resource = FiscalPeriodResource::class;

    protected static string $calendarKey = 'fiscal-periods';
}
