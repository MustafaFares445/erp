<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InventoryAlertSeverity: string implements HasColor, HasLabel
{
    use HasTranslatedLabel;

    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    public function getColor(): string
    {
        return match ($this) {
            self::Info => 'info',
            self::Warning => 'warning',
            self::Critical => 'danger',
        };
    }
}
