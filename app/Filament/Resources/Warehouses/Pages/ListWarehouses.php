<?php

declare(strict_types=1);

namespace App\Filament\Resources\Warehouses\Pages;

use App\Filament\Resources\Warehouses\WarehouseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListWarehouses extends ListRecords
{
    protected static string $resource = WarehouseResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    #[\Override]
    public function getSubheading(): string
    {
        return __('admin.inventory.warehouse.list_notice');
    }
}
