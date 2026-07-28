<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockLevels\Pages;

use App\Enums\InventoryExportType;
use App\Filament\Concerns\RequestsInventoryExports;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Widgets\InventoryStockStatistics;
use Filament\Resources\Pages\ListRecords;

final class ListStockLevels extends ListRecords
{
    use RequestsInventoryExports;

    protected static string $resource = StockLevelResource::class;

    #[\Override]
    public function getSubheading(): string
    {
        return __('admin.inventory.stock.sanctioned_write_notice');
    }

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [InventoryStockStatistics::class];
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [$this->inventoryExportAction(InventoryExportType::StockLevels)];
    }
}
