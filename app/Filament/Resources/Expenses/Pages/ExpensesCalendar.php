<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\Expenses\ExpenseResource;

final class ExpensesCalendar extends ResourceCalendarPage
{
    protected static string $resource = ExpenseResource::class;

    protected static string $calendarKey = 'expenses';
}
