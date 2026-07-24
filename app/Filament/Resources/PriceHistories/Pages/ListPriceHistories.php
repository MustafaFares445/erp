<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceHistories\Pages;

use App\Filament\Resources\PriceHistories\PriceHistoryResource;
use Filament\Resources\Pages\ListRecords;

final class ListPriceHistories extends ListRecords
{
    protected static string $resource = PriceHistoryResource::class;
}
