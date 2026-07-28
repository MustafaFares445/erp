<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Pages;

use App\Enums\OperationType;

final class ListDeliveries extends ListOperationsByType
{
    protected static function operationType(): OperationType
    {
        return OperationType::Delivery;
    }
}
