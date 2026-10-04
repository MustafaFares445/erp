<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

/** How a supplier warranty recovery was ultimately settled. */
enum WarrantyRecoveryOutcome: string implements HasLabel
{
    use HasTranslatedLabel;

    case CashRecovery = 'cash_recovery';
    case CreditNote = 'credit_note';
    case ReplacementUnit = 'replacement_unit';
    case PartsReplacement = 'parts_replacement';
    case Rejected = 'rejected';
}
