<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Pages;

use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListInventoryOperations extends ListRecords
{
    protected static string $resource = InventoryOperationResource::class;

    public static function canAccess(): bool
    {
        return InventoryOperationResource::canViewInventoryIndex();
    }

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
