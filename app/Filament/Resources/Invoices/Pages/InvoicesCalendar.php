<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\Invoices\InvoiceResource;

final class InvoicesCalendar extends ResourceCalendarPage
{
    protected static string $resource = InvoiceResource::class;

    protected static string $calendarKey = 'invoices';
}
