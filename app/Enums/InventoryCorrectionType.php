<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\InventoryOperation;

/**
 * The kind of {@see InventoryOperation} a correction reverses (WP-2.11, GAP-BW-02).
 *
 * Each case names exactly one allowed origin operation type — a correction is always linked to,
 * and never bypasses, the completed document it compensates.
 */
enum InventoryCorrectionType: string
{
    case Receipt = 'receipt';
    case Delivery = 'delivery';
    case Transfer = 'transfer';

    public function originOperationType(): OperationType
    {
        return match ($this) {
            self::Receipt => OperationType::Receipt,
            self::Delivery => OperationType::Delivery,
            self::Transfer => OperationType::InternalTransfer,
        };
    }
}
