<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\SerializedInventoryUnit;

/** Result of one canonical posting; an enclosing transaction retains its row locks. */
final readonly class InventoryPostingResult
{
    public function __construct(
        public InventoryStock $stock,
        public InventoryMovement $movement,
        public InventoryBalanceSnapshot $balanceBefore,
        public ?SerializedInventoryUnit $serializedUnit,
        public bool $alreadyPosted,
    ) {}
}
