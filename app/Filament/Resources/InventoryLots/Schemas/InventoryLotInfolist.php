<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryLots\Schemas;

use App\Enums\StockCondition;
use App\Filament\AdminModuleRegistry;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class InventoryLotInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.inventory.lot.sections.identity'))->columns(2)->schema([
                    TextEntry::make('lot_number')->label(__('admin.inventory.lot.fields.lot'))->placeholder('—'),
                    TextEntry::make('normalized_lot_number')->label(__('admin.inventory.lot.fields.normalized'))->placeholder('—'),
                    TextEntry::make('productVariant.sku')->label(__('admin.inventory.lot.fields.sku')),
                    TextEntry::make('productVariant.product.name')->label(__('admin.inventory.lot.fields.product')),
                    TextEntry::make('expires_at')->label(__('admin.inventory.lot.fields.expires_at'))->date()->placeholder('—'),
                    TextEntry::make('days_remaining')
                        ->label(__('admin.inventory.lot.fields.days_remaining'))
                        ->state(fn (InventoryLot $record): ?int => $record->daysRemaining()),
                    TextEntry::make('origin_reference')
                        ->label(__('admin.inventory.lot.fields.origin'))
                        ->state(fn (InventoryLot $record): string => self::originReference($record))
                        ->url(fn (InventoryLot $record): ?string => self::originUrl($record))
                        ->placeholder('—'),
                    TextEntry::make('expiry_state')
                        ->label(__('admin.inventory.lot.fields.expiry_state'))
                        ->state(fn (InventoryLot $record): string => $record->expiryState())
                        ->badge(),
                ]),
                Section::make(__('admin.inventory.lot.sections.balances'))->columns(3)->schema([
                    TextEntry::make('total_physical')
                        ->label(__('admin.inventory.stock.on_hand_quantity'))
                        ->state(fn (InventoryLot $record): float => $record->totalPhysicalQuantity())
                        ->numeric(decimalPlaces: 3),
                    TextEntry::make('saleable_quantity')
                        ->label(__('admin.inventory.stock.saleable_quantity'))
                        ->state(fn (InventoryLot $record): float => $record->totalConditionOnHandQuantity(StockCondition::Saleable))
                        ->numeric(decimalPlaces: 3),
                    TextEntry::make('quarantine_quantity')
                        ->label(__('admin.inventory.stock.quarantine_quantity'))
                        ->state(fn (InventoryLot $record): float => $record->totalConditionOnHandQuantity(StockCondition::Quarantine))
                        ->numeric(decimalPlaces: 3),
                    TextEntry::make('damaged_quantity')
                        ->label(__('admin.inventory.stock.damaged_quantity'))
                        ->state(fn (InventoryLot $record): float => $record->totalConditionOnHandQuantity(StockCondition::Damaged))
                        ->numeric(decimalPlaces: 3),
                    TextEntry::make('reserved_quantity')
                        ->label(__('admin.inventory.stock.reserved_quantity'))
                        ->state(fn (InventoryLot $record): float => $record->totalConditionReservedQuantity(StockCondition::Saleable))
                        ->numeric(decimalPlaces: 3),
                    TextEntry::make('available_quantity')
                        ->state(fn (InventoryLot $record): float => $record->totalAvailableQuantity())
                        ->numeric(decimalPlaces: 3),
                    TextEntry::make('warehouse_count')
                        ->label(__('admin.inventory.lot.fields.warehouses'))
                        ->state(fn (InventoryLot $record): int => $record->warehouseCount()),
                ]),
            ]);
    }

    private static function originReference(InventoryLot $lot): string
    {
        if ($lot->origin_source_type !== 'inventory_operation' || ! is_int($lot->origin_source_id)) {
            return $lot->origin_source_type === null
                ? '—'
                : str($lot->origin_source_type)->headline()->toString().' #'.($lot->origin_source_id ?? '—');
        }

        $operation = InventoryOperation::query()->whereKey($lot->origin_source_id)->first();

        return $operation instanceof InventoryOperation
            && is_string($operation->operation_number)
            && $operation->operation_number !== ''
                ? $operation->operation_number
                : __('admin.resources.inventory_receipts_menu').' #'.$lot->origin_source_id;
    }

    private static function originUrl(InventoryLot $lot): ?string
    {
        if ($lot->origin_source_type !== 'inventory_operation' || ! is_int($lot->origin_source_id)) {
            return null;
        }

        return AdminModuleRegistry::resolveResourceRecordLink(
            InventoryOperationResource::class,
            $lot->origin_source_id,
        );
    }
}
