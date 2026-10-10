<?php

declare(strict_types=1);

namespace App\Filament\Resources\Warehouses\Schemas;

use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Filament\Components\InventoryViewDiagnostics;
use App\Models\Warehouse;
use App\Support\QuantityFormatter;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class WarehouseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                InventoryViewDiagnostics::section(),
                InventoryViewDiagnostics::metrics(static fn (Warehouse $record): array => self::metrics($record)),
                Section::make(__('admin.inventory.warehouse.sections.identity'))
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                    ->schema([
                        TextEntry::make('code')->label(__('admin.inventory.warehouse.fields.code')),
                        TextEntry::make('name')->label(__('admin.inventory.warehouse.fields.name')),
                        IconEntry::make('is_active')->label(__('admin.inventory.warehouse.is_active'))->boolean(),
                        TextEntry::make('address')->label(__('admin.inventory.warehouse.fields.address'))->columnSpan(['md' => 2]),
                        TextEntry::make('created_at')->label(__('admin.inventory.warehouse.fields.created_at'))->dateTime(),
                        TextEntry::make('latitude')->label(__('admin.inventory.warehouse.fields.latitude'))->formatStateUsing(self::coordinate(...))->placeholder(__('—')),
                        TextEntry::make('longitude')->label(__('admin.inventory.warehouse.fields.longitude'))->formatStateUsing(self::coordinate(...))->placeholder(__('—')),
                    ]),
            ]);
    }

    /**
     * Totals add quantities across items, so they indicate workload, not a stock value.
     *
     * @return list<array{label: string, value: string, helper?: string|null, tone?: string|null}>
     */
    private static function metrics(Warehouse $record): array
    {
        $totals = $record->stocks()
            ->toBase()
            ->selectRaw('count(case when on_hand_quantity > 0 then 1 end) as skus')
            ->selectRaw('coalesce(sum(on_hand_quantity), 0) as on_hand')
            ->selectRaw('coalesce(sum(reserved_quantity), 0) as reserved')
            ->selectRaw('coalesce(sum(available_quantity), 0) as available')
            ->first();

        $incomingReceipts = $record->destinationOperations()
            ->where('operation_type', OperationType::Receipt->value)
            ->whereNotIn('stage', [OperationStage::Done->value, OperationStage::Canceled->value])
            ->count();
        $readyDeliveries = $record->sourceOperations()
            ->where('operation_type', OperationType::Delivery->value)
            ->where('stage', OperationStage::Ready->value)
            ->count();
        $transfersInTransit = $record->destinationOperations()
            ->where('operation_type', OperationType::InternalTransfer->value)
            ->whereIn('stage', [OperationStage::InTransit->value, OperationStage::PartiallyReceived->value])
            ->count();

        return [
            [
                'label' => __('admin.inventory.warehouse.fields.stock_skus'),
                'value' => (string) (int) ($totals->skus ?? 0),
            ],
            [
                'label' => __('admin.inventory.warehouse.fields.on_hand'),
                'value' => QuantityFormatter::display($totals->on_hand ?? 0),
            ],
            [
                'label' => __('admin.inventory.warehouse.fields.available'),
                'value' => QuantityFormatter::display($totals->available ?? 0),
                'helper' => __('admin.inventory.warehouse.fields.reserved').': '.QuantityFormatter::display($totals->reserved ?? 0),
            ],
            [
                'label' => __('admin.inventory.warehouse.fields.incoming_receipts'),
                'value' => (string) $incomingReceipts,
            ],
            [
                'label' => __('admin.inventory.warehouse.fields.ready_deliveries'),
                'value' => (string) $readyDeliveries,
                'tone' => $readyDeliveries > 0 ? 'primary' : null,
            ],
            [
                'label' => __('admin.inventory.warehouse.fields.transfers_in_transit'),
                'value' => (string) $transfersInTransit,
                'tone' => $transfersInTransit > 0 ? 'warning' : null,
            ],
        ];
    }

    /**
     * Coordinates are stored as DECIMAL(10,7); trailing zero padding is noise.
     */
    private static function coordinate(mixed $state): string
    {
        return is_numeric($state) ? (string) (float) $state : (string) $state;
    }
}
