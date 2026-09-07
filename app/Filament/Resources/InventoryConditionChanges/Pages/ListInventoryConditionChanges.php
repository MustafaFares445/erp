<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryConditionChanges\Pages;

use App\Filament\Resources\InventoryConditionChanges\InventoryConditionChangeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListInventoryConditionChanges extends ListRecords
{
    protected static string $resource = InventoryConditionChangeResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
