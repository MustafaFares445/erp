<?php

declare(strict_types=1);

namespace App\Filament\Resources\Returns\Pages;

use App\Filament\Resources\Returns\ReturnResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageReturns extends ManageRecords
{
    protected static string $resource = ReturnResource::class;
}
