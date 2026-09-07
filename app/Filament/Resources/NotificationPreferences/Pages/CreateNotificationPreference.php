<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationPreferences\Pages;

use App\Filament\Resources\NotificationPreferences\NotificationPreferenceResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateNotificationPreference extends CreateRecord
{
    protected static string $resource = NotificationPreferenceResource::class;
}
