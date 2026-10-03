<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum TransferDiscrepancyDisposition: string implements HasLabel
{
    use HasTranslatedLabel;

    case Shortage = 'shortage';
    case Damaged = 'damaged';
    case Cancelled = 'cancelled';
}
