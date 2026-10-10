<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\Tasks\TaskResource;

final class TasksCalendar extends ResourceCalendarPage
{
    protected static string $resource = TaskResource::class;

    protected static string $calendarKey = 'tasks';
}
