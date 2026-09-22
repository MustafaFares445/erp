<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a business constraint polices a value or merely tunes one.
 *
 * A {@see self::Limit} is a guard rail: something is refused, escalated, or
 * warned about when a value crosses it, so it carries a
 * {@see BusinessConstraintEnforcement} mode. A {@see self::Policy} is a
 * tunable number the system reads — an ageing ladder, a reminder schedule —
 * with nothing to enforce, so it carries no mode at all.
 *
 * Collapsing the two would force every policy value to answer a question it
 * has no answer to, which is why the distinction is modelled rather than
 * assumed.
 */
enum BusinessConstraintKind: string
{
    case Limit = 'limit';
    case Policy = 'policy';

    public function label(): string
    {
        return __('admin.constraints.kinds.'.$this->value);
    }

    public function requiresEnforcement(): bool
    {
        return $this === self::Limit;
    }
}
