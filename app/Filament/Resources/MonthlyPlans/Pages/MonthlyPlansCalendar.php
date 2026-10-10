<?php

declare(strict_types=1);

namespace App\Filament\Resources\MonthlyPlans\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\MonthlyPlans\MonthlyPlanResource;

final class MonthlyPlansCalendar extends ResourceCalendarPage
{
    protected static string $resource = MonthlyPlanResource::class;

    protected static string $calendarKey = 'monthly-plans';
}
