<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\RelationManagers;

use App\Enums\OperationStage;
use App\Models\InventoryOperation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LogicException;

/**
 * The inventory operations that received against this order, read-only.
 *
 * Deliberately offers no create, edit, or delete. A receipt is started from the
 * order's own Receive action and completed in the Inventory module, which is the
 * only thing that may move stock (R-001). Showing them here without offering to
 * write them is the whole point: the buyer can see what arrived without gaining
 * a second path to change it.
 *
 * The cost variance column is the reason this exists rather than a plain link.
 * It is what a buyer checks after delivery — did we pay what we agreed?
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
                TextColumn::make('cost_variance')
                    ->label(__('admin.purchasing.fields.cost_variance'))
                    ->state(fn (InventoryOperation $record): string => $this->costVariance($record))
                    ->color(fn (InventoryOperation $record): string => str_starts_with($this->costVariance($record), '-') ? 'success' : 'danger'),
            ])
            // No header, record, or bulk actions: this surface is a window, not a
            // control panel.
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    /**
     * Received value minus ordered value, over the lines this receipt covered.
     *
     * Negative means the goods came in under the agreed price. Both figures are
     * read from the stored costs rather than recomputed, so the number matches
     * what the order and the receipt each say on their own pages.
     */
    private function costVariance(InventoryOperation $operation): string
    {
        $ordered = 0.0;
        $received = 0.0;

        $orderLines = $this->order()->lines()->get()->keyBy(
            static fn (PurchaseOrderLine $line): string => $line->product_variant_id.':'.$line->unit_id,
        );

        foreach ($operation->lines()->get() as $line) {
            $orderLine = $orderLines->get($line->product_variant_id.':'.$line->unit_id);
            if ($orderLine === null) {
                continue;
            }

            if ($line->unit_cost === null) {
                continue;
            }

            $quantity = (float) $line->quantity;
            $ordered += $quantity * (float) $orderLine->unit_cost;
            $received += $quantity * (float) $line->unit_cost;
        }

        return number_format(round($received - $ordered, 2), 2, '.', '');
    }

    private function order(): PurchaseOrder
    {
        $record = $this->getOwnerRecord();

        // @codeCoverageIgnoreStart
        // Unreachable in practice; the guard exists only to satisfy static
        // analysis, which sees getOwnerRecord() as returning the base Model.
        if (! $record instanceof PurchaseOrder) {
            throw new LogicException('Expected the owner record of ReceiptsRelationManager to be a PurchaseOrder.');
        }

        // @codeCoverageIgnoreEnd

        return $record;
    }
}
