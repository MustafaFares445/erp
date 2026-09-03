<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

final class CreditExceedsReturn extends DomainException
{
    public static function make(): self
    {
        return new self('The requested credit quantity exceeds the quantity supported by the linked inventory return.');
    }
}
