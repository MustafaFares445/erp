<?php

declare(strict_types=1);

namespace App\Services\Sales\Exceptions;

use DomainException;

/**
 * Thrown when a sales posting needs an account that is missing, non-postable,
 * or inactive (FR-007).
 *
 * Every case names the configured role — receivable, revenue, deferred tax,
 * tax payable, or customer deposits — rather than only the account code, since
 * the fix is almost always in Sales Settings, not on the account itself.
 */
final class PostingAccountUnavailable extends DomainException
{
    public static function missing(string $role): self
    {
        return new self(__('admin.sales.errors.missing_posting_account', ['account' => $role]));
    }

    public static function notPostable(string $role, string $code): self
    {
        return new self(__('admin.sales.errors.account_not_postable', ['role' => $role, 'code' => $code]));
    }

    public static function inactive(string $role, string $code): self
    {
        return new self(__('admin.sales.errors.account_inactive', ['role' => $role, 'code' => $code]));
    }
}
