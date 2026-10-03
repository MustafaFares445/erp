<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockLevels\Pages;

use App\Enums\InventoryExportType;
use App\Filament\Concerns\HasTableViewTabs;
use App\Filament\Concerns\PersistsTablePresentation;
use App\Filament\Concerns\RequestsInventoryExports;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Widgets\InventoryStockStatistics;
use App\Models\WarehouseReplenishmentPolicy;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

final class ListStockLevels extends ListRecords
{
    use HasTableViewTabs;
    use PersistsTablePresentation;
    use RequestsInventoryExports;

    protected static string $resource = StockLevelResource::class;

    #[\Override]
    public function getSubheading(): string
    {
        return __('admin.inventory.stock.sanctioned_write_notice');
    }

    protected function savedTableViewPageKey(): string
    {
        return 'inventory.stock-levels';
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

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('Default'))->icon(Heroicon::OutlinedQueueList),
            'low_stock' => Tab::make(__('admin.inventory.stock.low_stock'))
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->whereExists(WarehouseReplenishmentPolicy::breachedSubquery())),
            'reserved' => Tab::make(__('admin.resources.reservations'))
                ->icon(Heroicon::OutlinedLockClosed)
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('reserved_quantity', '>', 0)),
            'empty' => Tab::make(__('Out of stock'))
                ->icon(Heroicon::OutlinedArchiveBoxXMark)
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('on_hand_quantity', '<=', 0)),
        ];
    }
}
