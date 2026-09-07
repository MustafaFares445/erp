<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

final class IllegalStatusTransition extends DomainException
{
    public static function between(string $document, string $from, string $to): self
    {
        return new self(sprintf(
            '%s cannot transition from %s to %s.',
            $document,
            $from,
            $to,
        ));
    }
}
