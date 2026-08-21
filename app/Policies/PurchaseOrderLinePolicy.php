<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;

/**
 * A line has no authorization of its own — it inherits its parent order's.
 *
 * A purchase order line has no independent existence: it cannot be reached, and
 * has no meaning, outside the order it belongs to. Giving it a separate
 * permission would create a second answer to "may this order be changed?", and
 * two answers to that question is how a sent order ends up editable through a
 * relation manager while its own page correctly refuses.
 *
 * `create` takes no record because there is none yet; the relation manager
 * scopes it to the order it is mounted on, and the service re-checks the parent
 * before writing (R-G).
 */
final class PurchaseOrderLinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', PurchaseOrder::class);
    }

    public function view(User $user, PurchaseOrderLine $line): bool
    {
        return $user->can('view', $line->purchaseOrder);
    }

    public function create(User $user): bool
    {
        return $user->can('create', PurchaseOrder::class);
    }

    public function update(User $user, PurchaseOrderLine $line): bool
    {
        return $user->can('update', $line->purchaseOrder);
    }

    public function delete(User $user, PurchaseOrderLine $line): bool
    {
        return $user->can('update', $line->purchaseOrder);
    }

    /**
     * Never permitted, matching every other purchasing record: the archive is
     * the audit trail (FR-009).
     */
    public function forceDelete(): bool
    {
        return false;
    }
}
