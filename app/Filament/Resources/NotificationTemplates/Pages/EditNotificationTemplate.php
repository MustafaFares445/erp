<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationTemplates\Pages;

use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use Filament\Resources\Pages\EditRecord;

final class EditNotificationTemplate extends EditRecord
{
    protected static string $resource = NotificationTemplateResource::class;
}
