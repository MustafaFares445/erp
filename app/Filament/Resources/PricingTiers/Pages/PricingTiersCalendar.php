<?php

declare(strict_types=1);

namespace App\Filament\Resources\PricingTiers\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\PricingTiers\PricingTierResource;

final class PricingTiersCalendar extends ResourceCalendarPage
{
    protected static string $resource = PricingTierResource::class;

    protected static string $calendarKey = 'pricing-tiers';
}
