<?php

declare(strict_types=1);

namespace App\Filament\Resources\SlaPolicies\Pages;

use App\Filament\Resources\SlaPolicies\SlaPolicyResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateSlaPolicy extends CreateRecord
{
    protected static string $resource = SlaPolicyResource::class;
}
