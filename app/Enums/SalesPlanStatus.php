<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum SalesPlanStatus: string implements HasLabel
{
    use HasTranslatedLabel;

    case Draft = 'Draft';
    case Published = 'Published';
    case InProgress = 'InProgress';
    case Completed = 'Completed';
    case Archived = 'Archived';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Published, self::Archived],
            self::Published => [self::InProgress, self::Archived],
            self::InProgress => [self::Completed, self::Archived],
            self::Completed => [self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
