<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

final class SupplierReferenceRequired extends DomainException
{
    public static function make(): self
    {
        return new self('A supplier invoice reference is required.');
    }
}
