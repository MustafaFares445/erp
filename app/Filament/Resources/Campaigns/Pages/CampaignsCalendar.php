<?php

declare(strict_types=1);

namespace App\Filament\Resources\Campaigns\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\Campaigns\CampaignResource;

final class CampaignsCalendar extends ResourceCalendarPage
{
    protected static string $resource = CampaignResource::class;

    protected static string $calendarKey = 'campaigns';
}
