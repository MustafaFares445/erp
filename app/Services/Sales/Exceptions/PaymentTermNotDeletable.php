<?php

declare(strict_types=1);

namespace App\Services\Sales\Exceptions;

use DomainException;

/**
 * Thrown when a payment term referenced by a document is deleted (FR-012).
 */
final class PaymentTermNotDeletable extends DomainException
{
    public static function referenced(string $name): self
    {
        return new self(__('admin.sales.errors.term_referenced', ['name' => $name]));
    }
}
