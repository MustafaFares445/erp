<?php

declare(strict_types=1);

namespace App\Filament\Resources\Interactions\Pages;

use App\Filament\Resources\Interactions\InteractionResource;
use Filament\Resources\Pages\ListRecords;

final class ListInteractions extends ListRecords
{
    protected static string $resource = InteractionResource::class;
}
