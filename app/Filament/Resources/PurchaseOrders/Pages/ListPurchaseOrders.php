<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Concerns\HasTableViewTabs;
use App\Filament\Concerns\PersistsTablePresentation;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListPurchaseOrders extends ListRecords
{
    use HasTableViewTabs;
    use PersistsTablePresentation;

    protected static string $resource = PurchaseOrderResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New Purchase Order'))];
    }

    protected function savedTableViewPageKey(): string
    {
        return 'purchasing.purchase-orders';
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),
            'approval' => Tab::make(__('Awaiting approval'))
                ->badge(PurchaseOrder::query()->where('status', PurchaseOrderStatus::PendingApproval->value)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', PurchaseOrderStatus::PendingApproval->value)),
            'ready_to_send' => Tab::make(__('Ready to send'))
                ->badge(PurchaseOrder::query()
                    ->where('status', PurchaseOrderStatus::Accepted->value)
                    ->whereNull('sent_at')
                    ->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', PurchaseOrderStatus::Accepted->value)
                    ->whereNull('sent_at')),
            'awaiting_supplier' => Tab::make(__('Awaiting supplier'))
                ->badge(PurchaseOrder::query()
                    ->whereNotNull('sent_at')
                    ->whereHas('confirmations', static fn (Builder $confirmation): Builder => $confirmation->where('confirmation_status', 'pending'))
                    ->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereNotNull('sent_at')
                    ->whereHas('confirmations', static fn (Builder $confirmation): Builder => $confirmation->where('confirmation_status', 'pending'))),
            'receiving' => Tab::make(__('Receiving'))
                ->badge(PurchaseOrder::query()->where('status', PurchaseOrderStatus::PartiallyReceived->value)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', PurchaseOrderStatus::PartiallyReceived->value)),
            'overdue' => Tab::make(__('Overdue'))
                ->badge(PurchaseOrder::query()
                    ->whereDate('expected_at', '<', today())
                    ->whereNotIn('status', self::terminalStatuses())
                    ->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereDate('expected_at', '<', today())
                    ->whereNotIn('status', self::terminalStatuses())),
            'accounting' => Tab::make(__('Accounting issues'))
                ->badge(PurchaseOrder::query()
                    ->whereIn('status', [PurchaseOrderStatus::Received->value, PurchaseOrderStatus::PartiallyReceived->value])
                    ->whereDoesntHave('bills')
                    ->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('status', [PurchaseOrderStatus::Received->value, PurchaseOrderStatus::PartiallyReceived->value])
                    ->whereDoesntHave('bills')),
            'completed' => Tab::make(__('Completed'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    PurchaseOrderStatus::Received->value,
                    PurchaseOrderStatus::Closed->value,
                ])),
        ];
    }

    /** @return list<string> */
    private static function terminalStatuses(): array
    {
        return [
            PurchaseOrderStatus::Received->value,
            PurchaseOrderStatus::Closed->value,
            PurchaseOrderStatus::Cancelled->value,
        ];
    }
}
