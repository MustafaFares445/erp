<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseInbounds\Pages;

use App\Enums\PurchaseInboundStatus;
use App\Filament\Concerns\HasTableViewTabs;
use App\Filament\Concerns\PersistsTablePresentation;
use App\Filament\Resources\PurchaseInbounds\PurchaseInboundResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListPurchaseInbounds extends ListRecords
{
    use HasTableViewTabs;
    use PersistsTablePresentation;

    protected static string $resource = PurchaseInboundResource::class;

    protected function savedTableViewPageKey(): string
    {
        return 'purchasing.purchase-inbounds';
    }

    /**
     * The bar starts with the shared "all" preset, but the page keeps opening
     * on the allocation work queue.
     */
    #[\Override]
    public function getDefaultActiveTab(): string
    {
        return 'awaiting_allocation';
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),
            'awaiting_allocation' => Tab::make(__('Awaiting Allocation'))
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('status', PurchaseInboundStatus::AwaitingAllocation->value)),
            'ready_to_receive' => Tab::make(__('Ready to Receive'))
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('status', PurchaseInboundStatus::AwaitingReceipt->value)),
            'partially_received' => Tab::make(__('Partially Received'))
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('status', PurchaseInboundStatus::PartiallyReceived->value)),
            'received' => Tab::make(__('Received'))
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('status', PurchaseInboundStatus::Received->value)),
        ];
    }
}
