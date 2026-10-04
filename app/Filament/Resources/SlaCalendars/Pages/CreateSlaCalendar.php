<?php

declare(strict_types=1);

namespace App\Filament\Resources\SlaCalendars\Pages;

use App\Filament\Resources\SlaCalendars\SlaCalendarResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateSlaCalendar extends CreateRecord
{
    protected static string $resource = SlaCalendarResource::class;
}
