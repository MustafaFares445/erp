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

    /**
     * Raised when an authorised actor overrides the expired-stock block and releases an expired
     * lot. Distinct from {@see self::Expiry}, which warns that a lot is *approaching* or past
     * expiry while still in stock: this one records that expired goods actually left.
     */
    case ExpiredStockReleased = 'expired_stock_released';
}
