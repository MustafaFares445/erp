<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\Quotations\QuotationResource;

final class QuotationsCalendar extends ResourceCalendarPage
{
    protected static string $resource = QuotationResource::class;

    protected static string $calendarKey = 'quotations';
}
