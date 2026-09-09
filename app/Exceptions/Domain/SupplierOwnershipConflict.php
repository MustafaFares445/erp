<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/**
 * Thrown when a bill's two possible sources of supplier truth disagree about
 * which one is authoritative (Phase 0 remediation).
 *
 * A PO-linked bill (`purchase_order_id` set) must derive its supplier from
 * the order and leave `supplier_id` null; a standalone bill has no order to
 * derive from and must set `supplier_id` directly. Allowing both at once is
 * exactly the duplication the remediation removed — two independently
 * settable sources for one fact, free to disagree.
 */
final class SupplierOwnershipConflict extends DomainException
{
    public static function bothSet(): self
    {
        return new self('A bill linked to a purchase order derives its supplier from that order; supplier_id must not also be set.');
    }

    public static function neitherSet(): self
    {
        return new self('A standalone bill (no purchase order) requires supplier_id.');
    }
}
