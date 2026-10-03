<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum WarrantyDurationUnit: string implements HasLabel
{
    use HasTranslatedLabel;

    case Days = 'days';
    case Months = 'months';
    case Years = 'years';
}
