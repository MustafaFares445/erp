<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum SerializedInventoryUnitStatus: string implements HasLabel
{
    use HasTranslatedLabel;

    case Pending = 'pending';
    case Available = 'available';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case ReturnedToSupplier = 'returned_to_supplier';
    case AdjustedOut = 'adjusted_out';
    case Consumed = 'consumed';
    case Damaged = 'damaged';
    case Disposed = 'disposed';
    case Unknown = 'unknown';
}
