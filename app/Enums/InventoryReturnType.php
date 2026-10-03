<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum InventoryReturnType: string implements HasLabel
{
    use HasTranslatedLabel;

    case Customer = 'customer';
    case Supplier = 'supplier';
}
