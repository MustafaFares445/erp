<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockMovements\Schemas;

use App\Filament\Components\InventoryViewDiagnostics;
use App\Filament\Resources\StockMovements\Tables\StockMovementsTable;
use App\Models\InventoryMovement;
use App\Models\Unit;
use App\Support\QuantityFormatter;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Stock movement View page: Operational health (StockMovementDiagnoser), key quantities and the
 * immutable ledger row grouped by movement, item, quantities, condition balances, tracking and source.
 */
final class StockMovementInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                InventoryViewDiagnostics::section(),
                InventoryViewDiagnostics::metrics(static fn (InventoryMovement $record): array => self::metrics($record)),
                Section::make(__('inventory_view_ux.ui.movement_section_movement'))
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                    ->schema([
                        TextEntry::make('created_at')
                            ->label(__('admin.inventory.movement.date'))
                            ->dateTime(),
                        TextEntry::make('movement_type')
                            ->label(__('admin.inventory.movement.type'))
                            ->badge(),
                        TextEntry::make('status')
                            ->formatStateUsing(static fn (string $state): string => $state === 'confirmed' ? __('Confirmed') : $state)
                            ->badge(),
                    ]),
                Section::make(__('inventory_view_ux.ui.movement_section_item'))
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                    ->schema([
                        TextEntry::make('productVariant.sku')
                            ->label(__('admin.inventory.stock.variant')),
                        TextEntry::make('productVariant.name')
                            ->label(__('admin.inventory.stock.variant_name')),
                        TextEntry::make('warehouse.code')
                            ->label(__('admin.inventory.stock.warehouse')),
                        TextEntry::make('warehouse.name')
                            ->label(__('admin.inventory.stock.warehouse_name')),
                        TextEntry::make('package.name')
                            ->label(__('admin.inventory.movement.package'))
                            ->placeholder(__('—')),
                    ]),
                Section::make(__('inventory_view_ux.ui.movement_section_quantities'))
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                    ->schema([
                        TextEntry::make('transaction_quantity')
                            ->label(__('admin.inventory.movement.transaction_quantity'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder(__('—')),
                        TextEntry::make('transactionUnit.symbol')
                            ->label(__('admin.inventory.movement.transaction_unit'))
                            ->placeholder(__('—')),
                        TextEntry::make('conversion_factor_snapshot')
                            ->label(__('admin.inventory.movement.conversion_factor'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder(__('—')),
                        TextEntry::make('base_quantity_delta')
                            ->label(__('admin.inventory.movement.base_quantity_delta'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder(__('—')),
                        TextEntry::make('quantity')
                            ->label(__('admin.inventory.movement.legacy_quantity'))
                            ->placeholder(__('—')),
                    ]),
                Section::make(__('inventory_view_ux.ui.movement_section_conditions'))
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                    ->schema([
                        TextEntry::make('stock_condition_from')
                            ->label(__('admin.inventory.movement.condition_from'))
                            ->badge()
                            ->placeholder(__('—')),
                        TextEntry::make('stock_condition_to')
                            ->label(__('admin.inventory.movement.condition_to'))
                            ->badge()
                            ->placeholder(__('—'))
                            ->columnSpan(['xl' => 3]),
                        TextEntry::make('condition_from_on_hand_before')
                            ->label(__('admin.inventory.movement.condition_from_before'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder(__('—')),
                        TextEntry::make('condition_from_on_hand_after')
                            ->label(__('admin.inventory.movement.condition_from_after'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder(__('—')),
                        TextEntry::make('condition_from_reserved_before')
                            ->label(__('admin.inventory.movement.condition_from_reserved_before'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder(__('—')),
                        TextEntry::make('condition_from_reserved_after')
                            ->label(__('admin.inventory.movement.condition_from_reserved_after'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder(__('—')),
                        TextEntry::make('condition_to_on_hand_before')
                            ->label(__('admin.inventory.movement.condition_to_before'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder(__('—')),
                        TextEntry::make('condition_to_on_hand_after')
                            ->label(__('admin.inventory.movement.condition_to_after'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder(__('—')),
                        TextEntry::make('condition_to_reserved_before')
                            ->label(__('admin.inventory.movement.condition_to_reserved_before'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder(__('—')),
                        TextEntry::make('condition_to_reserved_after')
                            ->label(__('admin.inventory.movement.condition_to_reserved_after'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder(__('—')),
                    ]),
                Section::make(__('inventory_view_ux.ui.movement_section_tracking'))
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextEntry::make('lot.lot_number')
                            ->label(__('admin.inventory.movement.lot'))
                            ->placeholder(__('—')),
                        TextEntry::make('serializedUnit.serial_number')
                            ->label(__('admin.inventory.movement.serial'))
                            ->placeholder(__('—')),
                    ]),
                Section::make(__('inventory_view_ux.ui.movement_section_source'))
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                    ->schema([
                        TextEntry::make('source_reference')
                            ->label(__('admin.inventory.movement.source'))
                            ->state(fn (InventoryMovement $record): string => StockMovementsTable::sourceReference($record))
                            ->url(fn (InventoryMovement $record): ?string => StockMovementsTable::sourceUrl($record)),
                        TextEntry::make('source_line_reference')
                            ->label(__('admin.inventory.movement.source_line'))
                            ->state(fn (InventoryMovement $record): ?string => $record->source_line_type === null
                                ? null
                                : sprintf('%s #%s', $record->source_line_type, $record->source_line_id ?? '—'))
                            ->placeholder(__('—')),
                        TextEntry::make('reversal_reference')
                            ->label(__('admin.inventory.movement.reversal_of'))
                            ->state(fn (InventoryMovement $record): ?string => $record->reversal_of_movement_id === null
                                ? null
                                : '#'.$record->reversal_of_movement_id)
                            ->url(fn (InventoryMovement $record): ?string => StockMovementsTable::reversalUrl($record))
                            ->placeholder(__('—')),
                        TextEntry::make('createdBy.name')
                            ->label(__('admin.inventory.movement.creator'))
                            ->default(__('admin.inventory.movement.system')),
                        TextEntry::make('notes')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return list<array{label: string, value: string, helper?: string|null, tone?: string|null}>
     */
    private static function metrics(InventoryMovement $record): array
    {
        $record->loadMissing(['reversals', 'productVariant.unit']);
        $unit = $record->productVariant?->unit;
        $suffix = $unit instanceof Unit ? ' '.($unit->symbol ?: $unit->code) : '';
        $quantity = (float) ($record->base_quantity_delta ?? $record->quantity);
        $reversed = (float) $record->reversals->sum(static fn (InventoryMovement $reversal): float => (float) ($reversal->base_quantity_delta ?? $reversal->quantity));
        $transactionUnit = $record->transactionUnit;

        return [
            [
                'label' => __('admin.inventory.movement.quantity'),
                'value' => QuantityFormatter::display($quantity).$suffix,
                'tone' => 'primary',
            ],
            [
                'label' => __('inventory_view_ux.ui.movement_reversed'),
                'value' => QuantityFormatter::display($reversed).$suffix,
                'tone' => $record->reversals->isNotEmpty() ? 'warning' : null,
            ],
            [
                'label' => __('inventory_view_ux.ui.movement_net_effect'),
                'value' => QuantityFormatter::display($quantity + $reversed).$suffix,
            ],
            [
                'label' => __('admin.inventory.movement.transaction_quantity'),
                'value' => $record->transaction_quantity === null
                    ? __('—')
                    : QuantityFormatter::display($record->transaction_quantity).($transactionUnit instanceof Unit ? ' '.($transactionUnit->symbol ?: $transactionUnit->code) : ''),
            ],
        ];
    }
}
