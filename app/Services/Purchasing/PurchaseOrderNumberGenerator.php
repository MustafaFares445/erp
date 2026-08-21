<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\PurchaseOrder;

/**
 * Assigns the human-readable purchase order number (FR-011, R-013).
 *
 * Deliberately the same mechanism `operation_number` and `order_number` already
 * use: read the current maximum under a row lock inside the creating
 * transaction, then increment. Three tables in this codebase already solve this
 * problem, and a fourth approach would be gratuitous divergence.
 *
 * A UUID would have been race-free without the lock, and was rejected: FR-011
 * requires a number a buyer can read out to a supplier over the phone.
 *
 * The lock is the ordering guarantee, and the unique index on
 * `purchase_order_number` is the final one — including over soft-deleted rows,
 * so a number quoted to a supplier is never reissued.
 */
final readonly class PurchaseOrderNumberGenerator
{
    private const string PREFIX = 'PO-';

    public function next(): string
    {
        $maxNumber = PurchaseOrder::withTrashed()
            ->whereNotNull('purchase_order_number')
            ->lockForUpdate()
            ->max('purchase_order_number');

        $sequence = is_string($maxNumber)
            ? (int) mb_substr($maxNumber, mb_strlen(self::PREFIX)) + 1
            : 1;

        return sprintf('%s%06d', self::PREFIX, $sequence);
    }
}
