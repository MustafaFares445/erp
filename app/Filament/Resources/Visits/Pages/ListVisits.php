<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits\Pages;

use App\Filament\Resources\Visits\VisitResource;
use Filament\Resources\Pages\ListRecords;

final class ListVisits extends ListRecords
{
    protected static string $resource = VisitResource::class;
}
