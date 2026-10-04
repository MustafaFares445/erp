<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportQueues\Pages;

use App\Filament\Resources\SupportQueues\SupportQueueResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSupportQueues extends ListRecords
{
    protected static string $resource = SupportQueueResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
