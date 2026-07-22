<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockLevels\Pages;

use App\Filament\Resources\StockLevels\StockLevelResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewStockLevel extends ViewRecord
{
    protected static string $resource = StockLevelResource::class;
}
