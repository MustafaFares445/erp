<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

final class QuarantineDispositionRejected extends DomainException
{
    public static function because(string $reason): self
    {
        return new self('Quarantine disposition rejected: '.$reason);
    }
}
