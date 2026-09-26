<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Concerns\ExportsSalesDocuments;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Widgets\OrdersOverview;
use App\Models\Order;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ListOrders extends ListRecords
{
    use ExportsSalesDocuments;

    protected static string $resource = OrderResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            $this->salesDocumentExportAction(),
        ];
    }

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [OrdersOverview::class];
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'pending' => Tab::make('Draft')
                ->badge(Order::query()->where('status', OrderStatus::Draft->value)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', OrderStatus::Draft->value)),
            'active' => Tab::make('Active')
                ->badge(Order::query()->active()->count())
                ->modifyQueryUsing(self::activeQuery(...)),
            'awaiting_fulfillment' => Tab::make('Awaiting fulfillment')
                ->badge(Order::query()->awaitingFulfillment()->count())
                ->modifyQueryUsing(self::awaitingFulfillmentQuery(...)),
            'requires_attention' => Tab::make('Requires attention')
                ->badge(Order::query()->blocked()->count())
                ->modifyQueryUsing(self::blockedQuery(...)),
            'completed' => Tab::make('Completed')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', OrderStatus::Closed->value)),
            'cancelled' => Tab::make('Cancelled')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', OrderStatus::Cancelled->value)),
        ];
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private static function activeQuery(Builder $query): Builder
    {
        return $query->active();
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private static function awaitingFulfillmentQuery(Builder $query): Builder
    {
        return $query->awaitingFulfillment();
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private static function blockedQuery(Builder $query): Builder
    {
        return $query->blocked();
    }

    /** @return list<string> */
    private function salesDocumentExportHeadings(): array
    {
        return ['order_number', 'customer', 'status', 'grand_total', 'payment_status', 'created_at'];
    }

    /** @return list<bool|float|int|string|null> */
    private function salesDocumentExportRow(Model $record): array
    {
        if (! $record instanceof Order) {
            return [];
        }

        return [
            (string) $record->order_number,
            $record->customer?->company_name,
            $record->status->value,
            $record->grand_total !== null ? (string) $record->grand_total : null,
            $record->payment_status?->value,
            $record->created_at?->toDateTimeString(),
        ];
    }

    private function salesDocumentExportFilename(): string
    {
        return 'orders-'.now()->format('Ymd-His').'.csv';
    }

    private function salesDocumentExportLogName(): string
    {
        return 'sales.order.exported';
    }
}
