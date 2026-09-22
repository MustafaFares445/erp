<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Settings\ConstraintGuard;
use App\Services\Settings\Exceptions\ConstraintBreached;

/**
 * What a {@see ConstraintGuard} check concluded.
 *
 * A guard either returns one of these or throws
 * {@see ConstraintBreached}; it never
 * returns a bare boolean, because "allowed" and "allowed only because an
 * approved override was presented" are different facts and callers that
 * record provenance need to tell them apart.
 */
enum ConstraintOutcome: string
{
    case Allowed = 'allowed';
    case Warned = 'warned';
    case Overridden = 'overridden';

    public function isBreach(): bool
    {
        return $this !== self::Allowed;
    }
}
