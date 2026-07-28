<?php

declare(strict_types=1);

namespace App\Filament\Resources\Returns\Pages;

use App\Enums\MovementType;
use App\Filament\Resources\Returns\ReturnResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageReturns extends ManageRecords
{
    protected static string $resource = ReturnResource::class;

    #[\Override]
    public function mount(): void
    {
        $this->redirect(StockMovementResource::getUrl('index', [
            'tableFilters' => ['movement_type' => ['value' => MovementType::Return->value]],
        ]));
    }
}
