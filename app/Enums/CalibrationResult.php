<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CalibrationResult: string implements HasColor, HasLabel
{
    use HasTranslatedLabel;

    case Passed = 'passed';
    case PassedWithAdjustment = 'passed_with_adjustment';
    case Failed = 'failed';

    public function isSuccessful(): bool
    {
        return $this !== self::Failed;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Passed => 'success',
            self::PassedWithAdjustment => 'warning',
            self::Failed => 'danger',
        };
    }
}
