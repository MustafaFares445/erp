<?php

declare(strict_types=1);

namespace App\Filament\Resources\Warehouses\Pages;

use App\Filament\Resources\InventoryReservations\InventoryReservationResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Models\Warehouse;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewWarehouse extends ViewRecord
{
    protected static string $resource = WarehouseResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            Action::make('viewStock')
                ->label(__('admin.inventory.warehouse.actions.view_stock'))
                ->icon(Heroicon::OutlinedChartBarSquare)
                ->url(fn (Warehouse $record): string => StockLevelResource::getUrl('index', [
                    'tableFilters' => ['warehouse_id' => ['value' => $record->getKey()]],
                ])),
            Action::make('viewMovements')
                ->label(__('admin.inventory.warehouse.actions.view_movements'))
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->url(fn (Warehouse $record): string => StockMovementResource::getUrl('index', [
                    'tableFilters' => ['warehouse_id' => ['value' => $record->getKey()]],
                ])),
            Action::make('viewReservations')
                ->label(__('admin.inventory.warehouse.actions.view_reservations'))
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('gray')
                ->visible(fn (): bool => InventoryReservationResource::canViewAny())
                ->url(fn (Warehouse $record): string => InventoryReservationResource::getUrl('index', [
                    'tableFilters' => ['warehouse_id' => ['value' => $record->getKey()]],
                ])),
            EditAction::make(),
        ];
    }
}
