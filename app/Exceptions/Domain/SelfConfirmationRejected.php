<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Models\InventoryAdjustment;
use DomainException;

final class SelfConfirmationRejected extends DomainException
{
    public static function forAdjustment(InventoryAdjustment $adjustment): self
    {
        $identity = $adjustment->adjustment_number ?? '#'.$adjustment->getKey();

        return new self(sprintf(
            'The user who created inventory adjustment %s cannot confirm it.',
            $identity,
        ));
    }
}
