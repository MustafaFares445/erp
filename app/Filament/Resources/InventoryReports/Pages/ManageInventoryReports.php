<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryReports\Pages;

use App\Filament\Resources\InventoryReports\InventoryReportResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageInventoryReports extends ManageRecords
{
    protected static string $resource = InventoryReportResource::class;
}
