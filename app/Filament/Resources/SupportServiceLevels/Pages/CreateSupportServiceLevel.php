<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportServiceLevels\Pages;

use App\Filament\Resources\SupportServiceLevels\SupportServiceLevelResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateSupportServiceLevel extends CreateRecord
{
    protected static string $resource = SupportServiceLevelResource::class;
}
