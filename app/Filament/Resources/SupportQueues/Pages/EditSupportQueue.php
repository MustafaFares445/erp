<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportQueues\Pages;

use App\Filament\Resources\SupportQueues\SupportQueueResource;
use App\Models\SupportQueue;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditSupportQueue extends EditRecord
{
    protected static string $resource = SupportQueueResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->visible(static fn (SupportQueue $record): bool => ! $record->is_system),
        ];
    }
}
