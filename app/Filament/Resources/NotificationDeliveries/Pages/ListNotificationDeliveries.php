<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationDeliveries\Pages;

use App\Filament\Resources\NotificationDeliveries\NotificationDeliveryResource;
use App\Filament\Resources\NotificationDeliveries\Widgets\FailedNotifications;
use Filament\Resources\Pages\ListRecords;

final class ListNotificationDeliveries extends ListRecords
{
    protected static string $resource = NotificationDeliveryResource::class;

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [FailedNotifications::class];
    }
}
