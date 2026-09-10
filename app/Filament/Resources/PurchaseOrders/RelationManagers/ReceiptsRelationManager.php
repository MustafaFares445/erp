<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\RelationManagers;

use App\Enums\OperationStage;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The inventory operations that received against this order, read-only.
 *
 * Deliberately offers no create, edit, or delete. A receipt is started from the
 * order's own Receive action and completed in the Inventory module, which is the
 * only thing that may move stock (R-001). Showing them here without offering to
 * write them is the whole point: the buyer can see what arrived without gaining
 * a second path to change it.
 *
 * Carries no cost comparison: a receipt line records no cost
 * (Phase 0 remediation — Inventory/Logistics owns zero monetary data), so
 * there is nothing here to compare against the order's commercial price
 * until a later phase's three-way match reintroduces that signal from a bill.
 */
final class ReceiptsRelationManager extends RelationManager
{
    protected static string $relationship = 'receipts';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('operation_number')
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('operation_number')
                    ->label(__('admin.purchasing.fields.receipts'))
                    ->placeholder('—'),
                TextColumn::make('stage')
                    ->label(__('admin.purchasing.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (OperationStage $state): string => $state->label())
                    ->color(static fn (OperationStage $state): string => match ($state) {
                        OperationStage::Done => 'success',
                        OperationStage::Canceled => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('destinationWarehouse.name')
                    ->label(__('admin.purchasing.fields.destination_warehouse')),
                TextColumn::make('completed_at')
                    ->label(__('admin.purchasing.fields.quantity_received'))
                    ->dateTime()
                    ->placeholder('—'),
            ])
            // No header, record, or bulk actions: this surface is a window, not a
            // control panel.
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
