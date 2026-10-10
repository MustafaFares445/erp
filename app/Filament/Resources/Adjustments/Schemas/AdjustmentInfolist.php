<?php

declare(strict_types=1);

namespace App\Filament\Resources\Adjustments\Schemas;

use App\Enums\AdjustmentStatus;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentItem;
use App\Services\Inventory\Diagnostics\InventoryAdjustmentDiagnoser;
use App\Support\QuantityFormatter;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use App\Filament\Components\InventoryViewDiagnostics;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * View-page detail for an {@see InventoryAdjustment}. The resulting-
 * movements section is a **read-only** cross-module link to the FI-2
 * `StockMovementResource` (FR-014) — never an editable relation (plan §0) —
 * resolved via {@see InventoryAdjustment::movements()}, which keeps this
 * namespace free of any direct reference to `InventoryMovement`.
 */
final class AdjustmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                InventoryViewDiagnostics::section(),
                InventoryViewDiagnostics::metrics(static fn (Model $record): array => self::metrics($record)),
                Section::make(__('inventory_view_ux.ui.adjustment_details'))
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                    ->schema([
                        TextEntry::make('adjustment_number')
                            ->label(__('admin.inventory.adjustment.adjustment_number'))
                            ->placeholder(__('admin.inventory.adjustment.number_pending')),
                        TextEntry::make('correctsAdjustment.adjustment_number')
                            ->label(__('admin.inventory.adjustment.corrects_adjustment'))
                            ->placeholder(__('—'))
                            ->url(fn (InventoryAdjustment $record): ?string => $record->corrects_adjustment_id === null
                                ? null
                                : AdjustmentResource::getUrl('view', ['record' => $record->corrects_adjustment_id])),
                        TextEntry::make('status')
                            ->label(__('admin.inventory.adjustment.status'))
                            ->badge()
                            ->color(fn (AdjustmentStatus $state): string => match ($state) {
                                AdjustmentStatus::Draft => 'warning',
                                AdjustmentStatus::Confirmed => 'success',
                            }),
                        TextEntry::make('warehouse.code')
                            ->label(__('admin.inventory.stock.warehouse')),
                        TextEntry::make('warehouse.name')
                            ->label(__('admin.inventory.stock.warehouse_name')),
                        TextEntry::make('reason_category')
                            ->label(__('admin.inventory.adjustment.reason_category'))
                            ->badge()
                            ->placeholder(__('—')),
                        TextEntry::make('reason')
                            ->label(__('admin.inventory.adjustment.reason'))
                            ->columnSpanFull(),
                        TextEntry::make('createdBy.name')
                            ->label(__('admin.inventory.movement.creator'))
                            ->default(__('admin.inventory.movement.system')),
                        TextEntry::make('created_at')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->dateTime(),
                    ]),
                Section::make(__('admin.inventory.adjustment.items_count'))
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->table([
                                TableColumn::make(__('admin.inventory.stock.variant')),
                                TableColumn::make(__('admin.inventory.stock.variant_name')),
                                TableColumn::make(__('admin.inventory.adjustment.stock_condition')),
                                TableColumn::make(__('admin.inventory.lot.fields.lot')),
                                TableColumn::make(__('admin.inventory.adjustment.old_quantity')),
                                TableColumn::make(__('admin.inventory.adjustment.new_quantity')),
                                TableColumn::make(__('admin.inventory.adjustment.difference')),
                            ])
                            ->schema([
                                TextEntry::make('productVariant.sku'),
                                TextEntry::make('productVariant.name'),
                                TextEntry::make('stock_condition')
                                    ->badge(),
                                TextEntry::make('lot.lot_number')
                                    ->placeholder(__('—')),
                                TextEntry::make('old_quantity')
                                    ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state)),
                                TextEntry::make('new_quantity')
                                    ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state)),
                                TextEntry::make('difference')
                                    ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state)),
                            ]),
                    ]),
                Section::make(__('admin.resources.stock_movements'))
                    ->columnSpanFull()
                    ->visible(fn (InventoryAdjustment $record): bool => $record->isConfirmed())
                    ->schema([
                        RepeatableEntry::make('movements')
                            ->label('')
                            ->schema([
                                TextEntry::make('created_at')
                                    ->label(__('admin.inventory.movement.date'))
                                    ->dateTime(),
                                TextEntry::make('quantity')
                                    ->label(__('admin.inventory.movement.quantity'))
                                    ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state)),
                                TextEntry::make('id')
                                    ->label(__('admin.inventory.condition_change.fields.movement'))
                                    ->formatStateUsing(static fn (mixed $state): string => '#'.(is_scalar($state) ? (string) $state : ''))
                                    ->url(fn (Model $record): string => StockMovementResource::getUrl('view', ['record' => $record->getKey()])),
                            ])
                            ->columns(3),
                    ]),
            ]);
    }

    /**
     * @return list<array{label: string, value: string, helper?: string|null, tone?: string|null}>
     */
    private static function metrics(Model $record): array
    {
        assert($record instanceof InventoryAdjustment);

        $record->loadMissing('items');
        $items = $record->items;
        $pendingHelper = $record->isDraft() ? __('inventory_view_ux.ui.adjustment_on_confirmation') : null;

        return [
            [
                'label' => __('inventory_view_ux.ui.adjustment_items'),
                'value' => (string) $items->count(),
                'tone' => $items->isEmpty() ? 'danger' : null,
            ],
            [
                'label' => __('inventory_view_ux.ui.adjustment_net_increase'),
                'value' => $pendingHelper === null ? QuantityFormatter::display(InventoryAdjustmentDiagnoser::netIncrease($record)) : __('—'),
                'helper' => $pendingHelper,
                'tone' => 'primary',
            ],
            [
                'label' => __('inventory_view_ux.ui.adjustment_net_decrease'),
                'value' => $pendingHelper === null ? QuantityFormatter::display(InventoryAdjustmentDiagnoser::netDecrease($record)) : __('—'),
                'helper' => $pendingHelper,
                'tone' => 'warning',
            ],
            [
                'label' => __('inventory_view_ux.ui.adjustment_lot_lines'),
                'value' => (string) $items->filter(static fn (InventoryAdjustmentItem $item): bool => $item->inventory_lot_id !== null)->count(),
            ],
        ];
    }
}
