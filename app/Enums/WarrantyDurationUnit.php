<?php

declare(strict_types=1);

namespace App\Enums;

enum WarrantyDurationUnit: string
{
    case Days = 'days';
    case Months = 'months';
    case Years = 'years';
}
