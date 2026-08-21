<?php

declare(strict_types=1);

namespace App\Filament\Resources\SlaPolicies\Pages;

use App\Filament\Resources\SlaPolicies\SlaPolicyResource;
use Filament\Resources\Pages\ListRecords;

final class ListSlaPolicies extends ListRecords
{
    protected static string $resource = SlaPolicyResource::class;
}
