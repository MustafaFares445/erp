<?php

declare(strict_types=1);

namespace App\Enums;

enum SalaryCalculationMode: string
{
    case PerformanceOnly = 'performance_only';
    case BasePlusPerformance = 'base_plus_performance';
}
