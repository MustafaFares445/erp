<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum ReconciliationScope: string implements HasLabel
{
    use HasTranslatedLabel;

    case InventoryLots = 'inventory_lots';
    case Receivables = 'receivables';
    case Payables = 'payables';
    case TaxRegister = 'tax_register';
}
