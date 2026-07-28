<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Pages;

use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Models\InventoryOperation;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditInventoryOperation extends EditRecord
{
    protected static string $resource = InventoryOperationResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->visible(fn (InventoryOperation $record): bool => $record->isDraft()),
        ];
    }
}
