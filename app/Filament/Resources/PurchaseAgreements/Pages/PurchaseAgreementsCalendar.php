<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseAgreements\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\PurchaseAgreements\PurchaseAgreementResource;

final class PurchaseAgreementsCalendar extends ResourceCalendarPage
{
    protected static string $resource = PurchaseAgreementResource::class;

    protected static string $calendarKey = 'purchase-agreements';
}
