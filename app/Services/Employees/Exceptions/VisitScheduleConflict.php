<?php

declare(strict_types=1);

namespace App\Services\Employees\Exceptions;

use DomainException;

/** @phpstan-consistent-constructor */
final class VisitScheduleConflict extends DomainException
{
    /** @param list<int> $conflictingVisitIds */
    public function __construct(public readonly array $conflictingVisitIds)
    {
        parent::__construct('This employee already has another visit during this time.');
    }
}
