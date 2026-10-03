<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum SalaryCalculationMode: string implements HasLabel
{
    use HasTranslatedLabel;

    case PerformanceOnly = 'performance_only';
    case BasePlusPerformance = 'base_plus_performance';
}
