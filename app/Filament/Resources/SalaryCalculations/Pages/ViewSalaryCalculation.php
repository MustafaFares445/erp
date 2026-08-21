<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalaryCalculations\Pages;

use App\Filament\Resources\SalaryCalculations\SalaryCalculationResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewSalaryCalculation extends ViewRecord
{
    protected static string $resource = SalaryCalculationResource::class;
}
