<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseRfqs\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\PurchaseRfqs\PurchaseRfqResource;

final class PurchaseRfqsCalendar extends ResourceCalendarPage
{
    protected static string $resource = PurchaseRfqResource::class;

    protected static string $calendarKey = 'purchase-rfqs';
}
