<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\InventoryOperation;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by `InventoryOperationService::cancel()` once an operation has moved to `canceled`,
 * from **inside** its transaction.
 *
 * Like {@see InventoryOperationCompleted} it carries no knowledge of who cares, so Inventory
 * never calls into Logistics directly. Listeners run synchronously in the cancelling
 * transaction: one that throws rolls the cancellation back with it.
 */
final class InventoryOperationCanceled
{
    use Dispatchable;

    public function __construct(
        public InventoryOperation $operation,
        public ?User $actor = null,
    ) {}
}
