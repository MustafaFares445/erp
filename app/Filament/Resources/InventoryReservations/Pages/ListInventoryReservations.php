<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryReservations\Pages;

use App\Filament\Resources\InventoryReservations\InventoryReservationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListInventoryReservations extends ListRecords
{
    protected static string $resource = InventoryReservationResource::class;

    #[\Override]
    public function getSubheading(): string
    {
        return __('admin.inventory.reservation.list_notice');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendar')->label(__('Calendar'))->icon('heroicon-o-calendar-days')->url(InventoryReservationResource::getUrl('calendar')),
        ];
    }
}
