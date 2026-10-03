<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentTemplates\Pages;

use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Filament\Resources\NotificationTemplates\Pages\Concerns\EditsNotificationMessages;
use Filament\Resources\Pages\EditRecord;

final class EditDocumentTemplate extends EditRecord
{
    use EditsNotificationMessages;

    protected static string $resource = DocumentTemplateResource::class;
}
