<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

final class PeriodCloseBlocked extends DomainException
{
    /** @param list<string> $reasons */
    public static function by(array $reasons): self
    {
        return new self('Fiscal period close is blocked: '.implode('; ', $reasons));
    }
}
