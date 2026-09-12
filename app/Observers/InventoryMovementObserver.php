<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\InventoryMovement;
use App\Services\Inventory\InventoryValuationService;
use LogicException;

/** Protects the inventory ledger from history rewrites outside a compensating posting. */
final readonly class InventoryMovementObserver
{
    public function created(InventoryMovement $movement): void
    {
        app(InventoryValuationService::class)->processStandaloneMovement($movement);
    }

    public function updating(): never
    {
        throw new LogicException('Inventory movements are immutable. Create a compensating movement instead.');
    }

    public function deleting(): never
    {
        throw new LogicException('Inventory movements are immutable. Create a compensating movement instead.');
    }
}
