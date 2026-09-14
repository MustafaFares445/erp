<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseInbounds\Pages;

use App\Enums\PurchaseInboundStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Filament\Resources\PurchaseInbounds\PurchaseInboundResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListPurchaseInbounds extends ListRecords
{
    protected static string $resource = PurchaseInboundResource::class;

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'needs_attention' => Tab::make('Needs Attention')
                ->modifyQueryUsing(self::needsAttention(...)),
            'awaiting_supplier' => Tab::make('Awaiting Supplier Confirmation')
                ->modifyQueryUsing(self::awaitingSupplier(...)),
            'awaiting_allocation' => Tab::make('Awaiting Allocation')
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('status', PurchaseInboundStatus::AwaitingAllocation->value)),
            'ready_to_receive' => Tab::make('Ready to Receive')
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('status', PurchaseInboundStatus::AwaitingReceipt->value)),
            'partially_received' => Tab::make('Partially Received')
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('status', PurchaseInboundStatus::PartiallyReceived->value)),
            'overdue' => Tab::make('Overdue')
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query
                    ->whereHas('purchaseOrder', static fn (Builder $po): Builder => $po->whereDate('expected_at', '<', today()))
                    ->whereNotIn('status', [PurchaseInboundStatus::Received->value, PurchaseInboundStatus::Cancelled->value])),
            'received' => Tab::make('Received')
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('status', PurchaseInboundStatus::Received->value)),
            'closed' => Tab::make('Cancelled / Closed')
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('status', PurchaseInboundStatus::Cancelled->value)),
            'all' => Tab::make('All'),
        ];
    }

    private static function awaitingSupplier(Builder $query): Builder
    {
        return $query->whereHas('purchaseOrder.supplier', static fn (Builder $supplier): Builder => $supplier->where('requires_confirmation', true))
            ->whereHas('purchaseOrder.confirmations', static fn (Builder $confirmation): Builder => $confirmation
                ->where('confirmation_status', SupplierConfirmationStatus::Pending->value));
    }

    private static function needsAttention(Builder $query): Builder
    {
        return $query->whereHas('purchaseOrder.confirmations', static fn (Builder $confirmation): Builder => $confirmation
            ->whereIn('confirmation_status', [
                SupplierConfirmationStatus::Partial->value,
                SupplierConfirmationStatus::Rejected->value,
            ]));
    }
}
