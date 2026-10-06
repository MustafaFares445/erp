<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryLots\Schemas;

use App\Enums\StockCondition;
use App\Enums\SupportPermission;
use App\Filament\AdminModuleRegistry;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Services\Inventory\InventoryLotTimelineService;
use App\Services\Support\LotQualitySignalService;
use App\Services\Support\TicketProductContextService;
use Filament\Infolists\Components\RepeatableEntry;
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
                    TextEntry::make('lot_number')->label(__('admin.inventory.lot.fields.lot'))->placeholder(__('—')),
                    TextEntry::make('normalized_lot_number')->label(__('admin.inventory.lot.fields.normalized'))->placeholder(__('—')),
                    TextEntry::make('productVariant.sku')->label(__('admin.inventory.lot.fields.sku')),
                    TextEntry::make('productVariant.product.name')->label(__('admin.inventory.lot.fields.product')),
                    TextEntry::make('expires_at')->label(__('admin.inventory.lot.fields.expires_at'))->date()->placeholder(__('—')),
                    TextEntry::make('days_remaining')
                        ->label(__('admin.inventory.lot.fields.days_remaining'))
                        ->state(fn (InventoryLot $record): ?int => $record->daysRemaining()),
                    TextEntry::make('origin_reference')
                        ->label(__('admin.inventory.lot.fields.origin'))
                        ->state(fn (InventoryLot $record): string => self::originReference($record))
                        ->url(fn (InventoryLot $record): ?string => self::originUrl($record))
                        ->placeholder(__('—')),
                    TextEntry::make('expiry_state')
                        ->label(__('admin.inventory.lot.fields.expiry_state'))
                        ->state(fn (InventoryLot $record): string => $record->expiryState())
                        ->badge(),
                    TextEntry::make('expiry_bucket')
                        ->label(__('Expiry window'))
                        ->state(fn (InventoryLot $record): string => match ($record->expiryBucket()) {
                            'expired' => __('Expired'),
                            'critical' => __('0–30 days'),
                            'warning' => __('31–60 days'),
                            'notice' => __('61–90 days'),
                            'healthy' => __('Beyond alert window'),
                            default => __('No expiry'),
                        })
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
                Section::make(__('Lot traceability'))
                    ->description(__('Immutable warehouse movement history for backward and forward lot tracing.'))
                    ->schema([
                        RepeatableEntry::make('traceability_timeline')
                            ->label(__('Movement history'))
                            ->state(fn (InventoryLot $record): array => app(InventoryLotTimelineService::class)->events($record))
                            ->schema([
                                TextEntry::make('occurred_at')->label(__('Date'))->dateTime(),
                                TextEntry::make('movement')->label(__('Movement'))->badge(),
                                TextEntry::make('warehouse')->label(__('Warehouse')),
                                TextEntry::make('transaction_quantity')->label(__('Transaction quantity'))->placeholder(__('—')),
                                TextEntry::make('transaction_unit')->label(__('Unit'))->placeholder(__('—')),
                                TextEntry::make('base_quantity_delta')->label(__('Base delta'))->numeric(decimalPlaces: 6),
                                TextEntry::make('condition_from')->label(__('From condition'))->badge()->placeholder(__('—')),
                                TextEntry::make('condition_to')->label(__('To condition'))->badge()->placeholder(__('—')),
                                TextEntry::make('serial')->label(__('Serial'))->placeholder(__('—')),
                                TextEntry::make('source')->label(__('Source document')),
                                TextEntry::make('counterparty')->label(__('Supplier / customer'))->placeholder(__('—')),
                                TextEntry::make('invoice')->label(__('Invoice'))->placeholder(__('—')),
                                TextEntry::make('notes')->label(__('Notes'))->placeholder(__('—'))->columnSpanFull(),
                            ])
                            ->columns(3),
                    ]),
                Section::make(__('Customer Complaints'))
                    ->columns(3)
                    ->visible(static fn (): bool => TicketProductContextService::enabled() && (auth()->user()?->can(SupportPermission::QualityComplaintView->value) ?? false))
                    ->schema([
                        TextEntry::make('quality_open')
                            ->label(__('Open Complaints'))
                            ->state(static fn (InventoryLot $record): int => app(LotQualitySignalService::class)->summary($record)['open_complaints']),
                        TextEntry::make('quality_customers')
                            ->label(__('Affected Customers'))
                            ->state(static fn (InventoryLot $record): int => app(LotQualitySignalService::class)->summary($record)['affected_customers']),
                        TextEntry::make('quality_quantity')
                            ->label(__('Affected Quantity'))
                            ->state(static fn (InventoryLot $record): string => mb_rtrim(mb_rtrim(app(LotQualitySignalService::class)->summary($record)['affected_quantity'], '0'), '.')),
                        TextEntry::make('quality_returns')
                            ->label(__('Returns'))
                            ->state(static fn (InventoryLot $record): int => app(LotQualitySignalService::class)->summary($record)['returns']),
                        TextEntry::make('quality_supplier')
                            ->label(__('Responsible supplier'))
                            ->state(static function (InventoryLot $record): string {
                                $supplier = app(TicketProductContextService::class)->supplierForLot($record);

                                return $supplier !== null ? $supplier->name : '—';
                            }),
                        TextEntry::make('quality_alert')
                            ->label(__('Potential Lot Quality Issue'))
                            ->state(static function (InventoryLot $record): string {
                                $alert = app(LotQualitySignalService::class)->summary($record)['alert'];

                                return $alert === null
                                    ? (string) __('No alert')
                                    : (string) __('Raised :date — Inventory decides on quarantine', ['date' => $alert->raised_at->toFormattedDateString()]);
                            })
                            ->badge()
                            ->color(static fn (InventoryLot $record): string => app(LotQualitySignalService::class)->summary($record)['alert'] === null ? 'gray' : 'warning')
                            ->columnSpan(2),
                    ]),
            ]);
    }

    private static function originReference(InventoryLot $lot): string
    {
        if ($lot->origin_source_type !== 'inventory_operation' || ! is_int($lot->origin_source_id)) {
            return $lot->origin_source_type === null
                ? '—'
                : __(str($lot->origin_source_type)->headline()->toString()).' #'.($lot->origin_source_id ?? '—');
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
