<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum CustomerAcceptanceStatus: string implements HasLabel
{
    use HasTranslatedLabel;

    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
