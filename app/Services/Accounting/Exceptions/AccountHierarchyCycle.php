<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use DomainException;

/**
 * Thrown when an account's parent would be itself or one of its own descendants
 * (FR-006).
 *
 * Without this the balance roll-up in FR-037 would have a cycle to walk, so the
 * write path refuses it rather than leaving every read path to survive one.
 */
final class AccountHierarchyCycle extends DomainException
{
    public static function between(string $accountCode, string $parentCode): self
    {
        return new self(__('admin.accounting.errors.hierarchy_cycle', [
            'code' => $accountCode,
            'parent' => $parentCode,
        ]));
    }
}
