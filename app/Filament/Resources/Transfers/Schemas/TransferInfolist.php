<?php

declare(strict_types=1);

namespace App\Filament\Resources\Transfers\Schemas;

use App\Enums\TransferStatus;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Models\StockTransfer;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * View-page detail for a {@see StockTransfer}. The resulting-movements
 * section is a **read-only** cross-module link to the FI-2
 * `StockMovementResource` (FR-015) — never an editable relation (plan §0) —
 * resolved via {@see StockTransfer::movements()}, which keeps this
 * namespace free of any direct reference to `InventoryMovement`.
 */
final class TransferInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('transfer_number')
                            ->label(__('admin.inventory.transfer.transfer_number'))
                            ->placeholder(__('admin.inventory.transfer.number_pending')),
                        TextEntry::make('status')
                            ->label(__('admin.inventory.transfer.status'))
                            ->badge()
                            ->color(fn (TransferStatus $state): string => match ($state) {
                                TransferStatus::Draft => 'warning',
                                TransferStatus::Dispatched => 'info',
                                TransferStatus::Received => 'success',
                            }),
                        TextEntry::make('fromWarehouse.code')
                            ->label(__('admin.inventory.transfer.from_warehouse')),
                        TextEntry::make('toWarehouse.code')
                            ->label(__('admin.inventory.transfer.to_warehouse')),
                        TextEntry::make('notes')
                            ->label(__('admin.inventory.transfer.notes'))
                            ->columnSpanFull(),
                        TextEntry::make('createdBy.name')
                            ->label(__('admin.inventory.movement.creator'))
                            ->default(__('admin.inventory.movement.system')),
                        TextEntry::make('created_at')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->dateTime(),
                    ]),
                Section::make(__('admin.inventory.transfer.items_count'))
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                TextEntry::make('productVariant.sku')
                                    ->label(__('admin.inventory.stock.variant')),
                                TextEntry::make('productVariant.name')
                                    ->label(__('admin.inventory.stock.variant_name')),
                                TextEntry::make('quantity')
                                    ->label(__('admin.inventory.transfer.quantity')),
                            ])
                            ->columns(3),
                    ]),
                Section::make(__('admin.inventory.movement.type'))
                    ->visible(fn (StockTransfer $record): bool => ! $record->isDraft())
                    ->schema([
                        RepeatableEntry::make('movements')
                            ->label('')
                            ->schema([
                                TextEntry::make('created_at')
                                    ->label(__('admin.inventory.movement.date'))
                                    ->dateTime(),
                                TextEntry::make('warehouse.code')
                                    ->label(__('admin.inventory.stock.warehouse')),
                                TextEntry::make('quantity')
                                    ->label(__('admin.inventory.movement.quantity')),
                                TextEntry::make('id')
                                    ->label(__('admin.inventory.movement.source'))
                                    ->url(fn (Model $record): string => StockMovementResource::getUrl('view', ['record' => $record->getKey()])),
                            ])
                            ->columns(4),
                    ]),
            ]);
    }
}
