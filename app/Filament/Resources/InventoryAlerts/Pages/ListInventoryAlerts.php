<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryAlerts\Pages;

use App\Filament\Resources\InventoryAlerts\InventoryAlertResource;
use Filament\Resources\Pages\ListRecords;

final class ListInventoryAlerts extends ListRecords
{
    protected static string $resource = InventoryAlertResource::class;

    #[\Override]
    public function getSubheading(): string
    {
        return __('admin.inventory.alerts.list_notice');
    }
}
