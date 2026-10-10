<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;

final class PurchaseOrdersCalendar extends ResourceCalendarPage
{
    protected static string $resource = PurchaseOrderResource::class;

    protected static string $calendarKey = 'purchase-orders';
}
