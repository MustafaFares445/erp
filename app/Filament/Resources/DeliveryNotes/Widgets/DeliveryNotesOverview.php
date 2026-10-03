<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryNotes\Widgets;

use App\Enums\OperationStage;
use App\Enums\SalesPermission;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Models\InventoryOperation;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class DeliveryNotesOverview extends StatsOverviewWidget
{
    #[\Override]
    public static function canView(): bool
    {
        return (bool) (auth()->user()?->can(SalesPermission::DeliveryNoteView->value) ?? false);
    }

    #[\Override]
    protected function getStats(): array
    {
        $readyToDispatch = InventoryOperation::query()->readyToDispatch()->count();
        $deliveredToday = InventoryOperation::query()->deliveries()
            ->where('stage', OperationStage::Done->value)
            ->whereDate('completed_at', today())
            ->count();
        $deliveredNotInvoiced = InventoryOperation::query()->deliveredNotInvoiced()->count();

        return [
            Stat::make(__('Ready to dispatch'), $readyToDispatch)
                ->description(__('Prepared, waiting to leave the warehouse'))
                ->url(DeliveryNoteResource::getUrl('index', ['tab' => 'ready'])),
            Stat::make(__('Delivered today'), $deliveredToday)
                ->description(__('Completed today')),
            Stat::make(__('Delivered, not invoiced'), $deliveredNotInvoiced)
                ->description(__('Needs an invoice'))
                ->url(DeliveryNoteResource::getUrl('index', ['tab' => 'delivered_not_invoiced'])),
        ];
    }
}
