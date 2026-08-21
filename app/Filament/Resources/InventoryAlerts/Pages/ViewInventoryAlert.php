<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryAlerts\Pages;

use App\Filament\Resources\InventoryAlerts\InventoryAlertResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewInventoryAlert extends ViewRecord
{
    protected static string $resource = InventoryAlertResource::class;
}
