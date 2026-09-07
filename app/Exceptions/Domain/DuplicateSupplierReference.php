<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

final class DuplicateSupplierReference extends DomainException
{
    public static function forReference(string $reference): self
    {
        return new self(sprintf('Supplier reference "%s" has already been recorded for this supplier.', $reference));
    }
}
