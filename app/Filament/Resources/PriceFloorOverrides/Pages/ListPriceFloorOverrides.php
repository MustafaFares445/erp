<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceFloorOverrides\Pages;

use App\Filament\Resources\PriceFloorOverrides\PriceFloorOverrideResource;
use Filament\Resources\Pages\ListRecords;

final class ListPriceFloorOverrides extends ListRecords
{
    protected static string $resource = PriceFloorOverrideResource::class;
}
