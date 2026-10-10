<?php

declare(strict_types=1);

namespace App\Filament\Resources\SerializedInventoryUnits\Pages;

use App\Enums\InventoryExportType;
use App\Filament\Concerns\RequestsInventoryExports;
use App\Filament\Resources\SerializedInventoryUnits\SerializedInventoryUnitResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListSerializedInventoryUnits extends ListRecords
{
    use RequestsInventoryExports;

    protected static string $resource = SerializedInventoryUnitResource::class;

    #[\Override]
    public function getSubheading(): string
    {
        return __('admin.inventory.serialized_unit.list_notice');
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendar')->label(__('Calendar'))->icon('heroicon-o-calendar-days')->url(SerializedInventoryUnitResource::getUrl('calendar')), $this->inventoryExportAction(InventoryExportType::Devices)];
    }
}
