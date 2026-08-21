<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockReservations\Pages;

use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockReservations\StockReservationResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageStockReservations extends ManageRecords
{
    protected static string $resource = StockReservationResource::class;

    #[\Override]
    public function mount(): void
    {
        $this->redirect(StockLevelResource::getUrl('index', [
            'tableFilters' => ['reserved' => ['isActive' => true]],
        ]));
    }
}
