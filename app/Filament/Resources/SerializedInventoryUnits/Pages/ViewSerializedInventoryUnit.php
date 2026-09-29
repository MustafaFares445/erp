<?php

declare(strict_types=1);

namespace App\Filament\Resources\SerializedInventoryUnits\Pages;

use App\Filament\AdminModuleRegistry;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\MaintenanceSchedules\MaintenanceScheduleResource;
use App\Filament\Resources\SerializedInventoryUnits\SerializedInventoryUnitResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Models\InventoryMovement;
use App\Models\SerializedInventoryUnit;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewSerializedInventoryUnit extends ViewRecord
{
    protected static string $resource = SerializedInventoryUnitResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewMovements')
                ->label(__('admin.inventory.serialized.actions.view_movements'))
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->url(fn (SerializedInventoryUnit $record): string => StockMovementResource::getUrl('index', [
                    'tableFilters' => [
                        'serialized_inventory_unit_id' => ['value' => $record->getKey()],
                    ],
                ])),
            Action::make('viewMaintenance')
                ->label(__('admin.inventory.serialized.actions.view_maintenance'))
                ->icon(Heroicon::OutlinedWrenchScrewdriver)
                ->url(fn (SerializedInventoryUnit $record): string => MaintenanceScheduleResource::getUrl('index', [
                    'tableFilters' => [
                        'serialized_inventory_unit_id' => ['value' => $record->getKey()],
                    ],
                ])),
            Action::make('openReceipt')
                ->label(__('admin.inventory.serialized.actions.open_receipt'))
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->visible(fn (SerializedInventoryUnit $record): bool => self::receiptOperationId($record) !== null)
                ->url(function (SerializedInventoryUnit $record): ?string {
                    $operationId = self::receiptOperationId($record);

                    return $operationId !== null
                        ? AdminModuleRegistry::resolveResourceRecordLink(InventoryOperationResource::class, $operationId)
                        : null;
                }),
        ];
    }

    private static function receiptOperationId(SerializedInventoryUnit $unit): ?int
    {
        $movement = $unit->receiptMovement()->first();

        if (
            ! $movement instanceof InventoryMovement
            || $movement->source_type !== 'inventory_operation'
            || ! is_int($movement->source_id)
        ) {
            return null;
        }

        return $movement->source_id;
    }
}
