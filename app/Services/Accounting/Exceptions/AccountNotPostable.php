<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use DomainException;

/**
 * Thrown when an account that has children is asked to become a posting target
 * (FR-007).
 *
 * A postable parent would mix amounts posted directly to it with amounts rolled
 * up from its children, and no report could separate the two (research.md R-005).
 * The fix is to post to a leaf instead, not to relax this.
 */
final class AccountNotPostable extends DomainException
{
    public static function hasChildren(string $accountCode, int $childCount): self
    {
        return new self(__('admin.accounting.errors.parent_not_postable', [
            'code' => $accountCode,
            'count' => $childCount,
        ]));
    }
}
