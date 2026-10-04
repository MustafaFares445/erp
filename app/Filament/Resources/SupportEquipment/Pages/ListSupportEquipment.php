<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportEquipment\Pages;

use App\Filament\Resources\SupportEquipment\SupportEquipmentResource;
use Filament\Resources\Pages\ListRecords;

final class ListSupportEquipment extends ListRecords
{
    protected static string $resource = SupportEquipmentResource::class;
}
