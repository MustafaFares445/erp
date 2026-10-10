<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportEntitlements\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\SupportEntitlements\SupportEntitlementResource;

final class SupportEntitlementsCalendar extends ResourceCalendarPage
{
    protected static string $resource = SupportEntitlementResource::class;

    protected static string $calendarKey = 'support-entitlements';
}
