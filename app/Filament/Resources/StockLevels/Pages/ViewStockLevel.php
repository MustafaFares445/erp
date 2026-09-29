<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockLevels\Pages;

use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockLevels\Tables\StockLevelsTable;
use App\Filament\Resources\WarehouseReplenishmentPolicies\WarehouseReplenishmentPolicyResource;
use App\Models\InventoryStock;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewStockLevel extends ViewRecord
{
    protected static string $resource = StockLevelResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewMovements')
                ->label(__('admin.inventory.stock.actions.view_movements'))
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->url(fn (InventoryStock $record): string => StockLevelsTable::packageMovementsUrl($record)),
            Action::make('replenishmentPolicy')
                ->label(__('admin.inventory.stock.actions.replenishment_policy'))
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->url(fn (InventoryStock $record): string => WarehouseReplenishmentPolicyResource::getUrl('index', [
                    'tableFilters' => [
                        'warehouse_id' => ['value' => $record->warehouse_id],
                    ],
                ])),
        ];
    }
}
