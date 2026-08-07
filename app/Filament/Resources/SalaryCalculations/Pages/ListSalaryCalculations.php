<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalaryCalculations\Pages;

use App\Filament\Resources\SalaryCalculations\SalaryCalculationResource;
use Filament\Resources\Pages\ListRecords;

final class ListSalaryCalculations extends ListRecords
{
    protected static string $resource = SalaryCalculationResource::class;
}
