<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockMovements\Pages;

use App\Enums\InventoryExportType;
use App\Filament\Concerns\RequestsInventoryExports;
use App\Filament\Resources\StockMovements\StockMovementResource;
use Filament\Resources\Pages\ListRecords;

final class ListStockMovements extends ListRecords
{
    use RequestsInventoryExports;

    protected static string $resource = StockMovementResource::class;

    #[\Override]
    public function getSubheading(): string
    {
        return __('admin.inventory.movement.list_notice');
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [$this->inventoryExportAction(InventoryExportType::Movements)];
    }
}
