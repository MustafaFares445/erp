<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\InventoryOperation;

/**
 * The physical movement kind of an {@see InventoryOperation} (FR-001, data-model.md §1).
 *
 * No `Dropship` case: absent from the SRS and out of scope (FR-018, A-005).
 */
enum OperationType: string
{
    case Receipt = 'receipt';
    case Delivery = 'delivery';
    case InternalTransfer = 'internal_transfer';

    public function requiresSourceWarehouse(): bool
    {
        return match ($this) {
            self::Receipt => false,
            self::Delivery, self::InternalTransfer => true,
        };
    }

    public function requiresDestinationWarehouse(): bool
    {
        return match ($this) {
            self::Delivery => false,
            self::Receipt, self::InternalTransfer => true,
        };
    }

    public function label(): string
    {
        return __('admin.inventory.operation.types.'.$this->value);
    }
}
