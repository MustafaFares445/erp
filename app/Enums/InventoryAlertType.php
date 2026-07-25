<?php

declare(strict_types=1);

namespace App\Enums;

enum InventoryAlertType: string
{
    case LowStock = 'low_stock';
    case OutOfStock = 'out_of_stock';
    case Expiry = 'expiry';
    case TransferDiscrepancy = 'transfer_discrepancy';
    case ImportError = 'import_error';
    case DuplicateIdentity = 'duplicate_identity';
    case MissingDeviceIdentity = 'missing_device_identity';
}
