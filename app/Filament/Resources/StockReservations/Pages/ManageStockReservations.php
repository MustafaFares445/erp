<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockReservations\Pages;

use App\Filament\Resources\StockReservations\StockReservationResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageStockReservations extends ManageRecords
{
    protected static string $resource = StockReservationResource::class;
}
