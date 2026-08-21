<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\InventoryOperation;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by `InventoryOperationService::complete()` once an operation has moved
 * to `done`, from **inside** its transaction.
 *
 * Deliberately carries no knowledge of who cares. Purchasing listens for it to
 * advance a purchase order's received quantities, but Inventory does not know
 * that: calling a purchasing method directly from `InventoryOperationService`
 * would invert the dependency and break the folder-level domain boundary
 * Principle II exists to hold (R-002).
 *
 * Listeners run **synchronously and in the completing transaction**, which is
 * the point. A queued listener would open a window in which stock exists but
 * the purchase order still shows nothing received, and a job failure would leave
 * the two permanently divergent. A listener that throws rolls the completion
 * back with it.
 *
 * @see /specs/017-purchasing-orders-suppliers/research.md R-002
 */
final class InventoryOperationCompleted
{
    use Dispatchable;

    public function __construct(
        public InventoryOperation $operation,
        public ?User $actor = null,
    ) {}
}
