<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryLots\Pages;

use App\Enums\InventoryExportType;
use App\Filament\Concerns\RequestsInventoryExports;
use App\Filament\Resources\InventoryLots\InventoryLotResource;
use Filament\Resources\Pages\ListRecords;

final class ListInventoryLots extends ListRecords
{
    use RequestsInventoryExports;

    protected static string $resource = InventoryLotResource::class;

    #[\Override]
    public function getSubheading(): string
    {
        return __('admin.inventory.lot.list_notice');
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [$this->inventoryExportAction(InventoryExportType::ExpiryLots)];
    }
}
