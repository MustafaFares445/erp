<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum InstallationCheckResult: string implements HasLabel
{
    use HasTranslatedLabel;

    case Pending = 'pending';
    case Passed = 'passed';
    case Failed = 'failed';
    case NotApplicable = 'not_applicable';

    /** Normalises a form state (enum instance or raw value) into a result; unknown input is Pending. */
    public static function fromState(mixed $state): self
    {
        return $state instanceof self ? $state : (self::tryFrom(is_string($state) ? $state : '') ?? self::Pending);
    }

    public function satisfiesCommissioning(): bool
    {
        return in_array($this, [self::Passed, self::NotApplicable], true);
    }
}
