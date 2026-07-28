<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Pages;

use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateInventoryOperation extends CreateRecord
{
    protected static string $resource = InventoryOperationResource::class;
}
