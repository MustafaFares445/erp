<?php

declare(strict_types=1);

namespace App\Filament\Resources\Performance\Pages;

use App\Filament\Resources\Performance\PerformanceResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewPerformanceScore extends ViewRecord
{
    protected static string $resource = PerformanceResource::class;
}
