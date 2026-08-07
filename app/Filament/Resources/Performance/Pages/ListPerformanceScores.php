<?php

declare(strict_types=1);

namespace App\Filament\Resources\Performance\Pages;

use App\Filament\Resources\Performance\PerformanceResource;
use Filament\Resources\Pages\ListRecords;

final class ListPerformanceScores extends ListRecords
{
    protected static string $resource = PerformanceResource::class;
}
