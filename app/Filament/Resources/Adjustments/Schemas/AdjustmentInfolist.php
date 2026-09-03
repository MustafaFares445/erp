<?php

declare(strict_types=1);

namespace App\Filament\Resources\Adjustments\Schemas;

use App\Enums\AdjustmentStatus;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Models\InventoryAdjustment;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
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
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('adjustment_number')
                            ->label(__('admin.inventory.adjustment.adjustment_number'))
                            ->placeholder(__('admin.inventory.adjustment.number_pending')),
                        TextEntry::make('correctsAdjustment.adjustment_number')
                            ->label(__('admin.inventory.adjustment.corrects_adjustment'))
                            ->placeholder('—')
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
                            ->placeholder('—'),
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
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                TextEntry::make('productVariant.sku')
                                    ->label(__('admin.inventory.stock.variant')),
                                TextEntry::make('productVariant.name')
                                    ->label(__('admin.inventory.stock.variant_name')),
                                TextEntry::make('stock_condition')
                                    ->label(__('admin.inventory.adjustment.stock_condition'))
                                    ->badge(),
                                TextEntry::make('old_quantity')
                                    ->label(__('admin.inventory.adjustment.old_quantity')),
                                TextEntry::make('new_quantity')
                                    ->label(__('admin.inventory.adjustment.new_quantity')),
                                TextEntry::make('difference')
                                    ->label(__('admin.inventory.adjustment.difference')),
                            ])
                            ->columns(6),
                    ]),
                Section::make(__('admin.inventory.movement.type'))
                    ->visible(fn (InventoryAdjustment $record): bool => $record->isConfirmed())
                    ->schema([
                        RepeatableEntry::make('movements')
                            ->label('')
                            ->schema([
                                TextEntry::make('created_at')
                                    ->label(__('admin.inventory.movement.date'))
                                    ->dateTime(),
                                TextEntry::make('quantity')
                                    ->label(__('admin.inventory.movement.quantity')),
                                TextEntry::make('id')
                                    ->label(__('admin.inventory.movement.source'))
                                    ->url(fn (Model $record): string => StockMovementResource::getUrl('view', ['record' => $record->getKey()])),
                            ])
                            ->columns(3),
                    ]),
            ]);
    }
}
