<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationDeliveries\Pages;

use App\Filament\Resources\NotificationDeliveries\NotificationDeliveryResource;
use App\Filament\Widgets\NotificationVolumeReport;
use Filament\Resources\Pages\ListRecords;

final class ListNotificationDeliveries extends ListRecords
{
    protected static string $resource = NotificationDeliveryResource::class;

    /** @return array<class-string> */
    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [NotificationVolumeReport::class];
    }
}
