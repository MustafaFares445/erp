<?php

declare(strict_types=1);

namespace App\Enums;

enum SerializedInventoryUnitStatus: string
{
    case Pending = 'pending';
    case Available = 'available';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case AdjustedOut = 'adjusted_out';
    case Consumed = 'consumed';
    case Damaged = 'damaged';
    case Disposed = 'disposed';
    case Unknown = 'unknown';
}
