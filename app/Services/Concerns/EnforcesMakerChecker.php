<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use App\Models\User;
use DomainException;

/**
 * Shared separation-of-duties primitive.
 *
 * This concern owns actor identity comparison only. Domain services keep
 * control of their workflow-specific exception type and message.
 */
trait EnforcesMakerChecker
{
    protected function sameActor(?int $makerId, User $checker): bool
    {
        $checkerId = $checker->getKey();

        return $makerId !== null
            && is_int($checkerId)
            && $makerId === $checkerId;
    }

    protected function assertDifferentActor(?int $makerId, User $checker, string $message): void
    {
        if ($this->sameActor($makerId, $checker)) {
            throw new DomainException($message);
        }
    }
}
