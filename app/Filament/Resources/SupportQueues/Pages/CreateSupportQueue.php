<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportQueues\Pages;

use App\Filament\Resources\SupportQueues\SupportQueueResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateSupportQueue extends CreateRecord
{
    protected static string $resource = SupportQueueResource::class;
}
