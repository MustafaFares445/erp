<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum InventoryCorrectionStatus: string implements HasLabel
{
    use HasTranslatedLabel;

    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this !== self::Draft;
    }
}
