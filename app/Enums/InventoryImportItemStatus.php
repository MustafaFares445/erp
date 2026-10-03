<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum InventoryImportItemStatus: string implements HasLabel
{
    use HasTranslatedLabel;

    case Valid = 'valid';
    case Invalid = 'invalid';
    case Applying = 'applying';
    case Applied = 'applied';
    case Rejected = 'rejected';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Invalid, self::Applied, self::Rejected], true);
    }
}
