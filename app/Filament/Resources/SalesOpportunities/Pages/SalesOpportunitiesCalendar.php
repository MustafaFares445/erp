<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesOpportunities\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\SalesOpportunities\SalesOpportunityResource;

final class SalesOpportunitiesCalendar extends ResourceCalendarPage
{
    protected static string $resource = SalesOpportunityResource::class;

    protected static string $calendarKey = 'sales-opportunities';
}
