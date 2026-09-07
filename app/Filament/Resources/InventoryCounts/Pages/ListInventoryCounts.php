<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryCounts\Pages;

use App\Filament\Resources\InventoryCounts\InventoryCountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListInventoryCounts extends ListRecords
{
    protected static string $resource = InventoryCountResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Open count'),
        ];
    }
}
