<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Models\InventoryStock;

/**
 * Immutable base-quantity view of a materialized stock balance at one point
 * in a posting transaction.
 */
final readonly class InventoryBalanceSnapshot
{
    public function __construct(
        public string $onHandQuantity,
        public string $reservedQuantity,
        public string $damagedQuantity,
        public string $availableQuantity,
    ) {}

    public static function fromStock(InventoryStock $stock): self
    {
        return new self(
            onHandQuantity: (string) $stock->on_hand_quantity,
            reservedQuantity: (string) $stock->reserved_quantity,
            damagedQuantity: (string) $stock->damaged_quantity,
            availableQuantity: (string) $stock->available_quantity,
        );
    }

    /**
     * @return array{on_hand_quantity: float, reserved_quantity: float, damaged_quantity: float, available_quantity: float}
     */
    public function toAuditValues(): array
    {
        return [
            'on_hand_quantity' => (float) $this->onHandQuantity,
            'reserved_quantity' => (float) $this->reservedQuantity,
            'damaged_quantity' => (float) $this->damagedQuantity,
            'available_quantity' => (float) $this->availableQuantity,
        ];
    }
}
