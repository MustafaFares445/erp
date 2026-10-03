<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum SerializedCustodyType: string implements HasLabel
{
    use HasTranslatedLabel;

    case Warehouse = 'warehouse';
    case InTransit = 'in_transit';
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Maintenance = 'maintenance';
    case Disposed = 'disposed';
    case Unknown = 'unknown';
}
