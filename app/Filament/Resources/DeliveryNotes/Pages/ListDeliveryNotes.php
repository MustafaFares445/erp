<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryNotes\Pages;

use App\Enums\OperationStage;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\DeliveryNotes\Widgets\DeliveryNotesOverview;
use App\Models\InventoryOperation;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListDeliveryNotes extends ListRecords
{
    protected static string $resource = DeliveryNoteResource::class;

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [DeliveryNotesOverview::class];
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),
            'ready' => Tab::make(__('Ready to dispatch'))
                ->badge(InventoryOperation::query()->readyToDispatch()->count())
                ->modifyQueryUsing(self::readyToDispatchQuery(...)),
            'delivered_not_invoiced' => Tab::make(__('Delivered, not invoiced'))
                ->badge(InventoryOperation::query()->deliveredNotInvoiced()->count())
                ->modifyQueryUsing(self::deliveredNotInvoicedQuery(...)),
            'done' => Tab::make(__('Delivered'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('stage', OperationStage::Done->value)),
            'cancelled' => Tab::make(__('Cancelled'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('stage', OperationStage::Canceled->value)),
        ];
    }

    /**
     * @param  Builder<InventoryOperation>  $query
     * @return Builder<InventoryOperation>
     */
    private static function readyToDispatchQuery(Builder $query): Builder
    {
        return $query->where('stage', OperationStage::Ready->value);
    }

    /**
     * @param  Builder<InventoryOperation>  $query
     * @return Builder<InventoryOperation>
     */
    private static function deliveredNotInvoicedQuery(Builder $query): Builder
    {
        return $query->where('stage', OperationStage::Done->value)->whereDoesntHave('invoiceDeliveryLink');
    }
}
