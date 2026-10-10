<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierConfirmations\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;

final class SupplierConfirmationsCalendar extends ResourceCalendarPage
{
    protected static string $resource = SupplierConfirmationResource::class;

    protected static string $calendarKey = 'supplier-confirmations';
}
