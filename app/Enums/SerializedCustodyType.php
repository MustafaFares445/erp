<?php

declare(strict_types=1);

namespace App\Enums;

enum SerializedCustodyType: string
{
    case Warehouse = 'warehouse';
    case InTransit = 'in_transit';
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Maintenance = 'maintenance';
    case Disposed = 'disposed';
    case Unknown = 'unknown';
}
