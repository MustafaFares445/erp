<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum InventoryAlertSeverity: string implements HasLabel
{
    use HasTranslatedLabel;

    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
