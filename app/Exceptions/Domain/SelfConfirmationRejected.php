<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Models\InventoryAdjustment;
use App\Models\InventoryCount;
use DomainException;

final class SelfConfirmationRejected extends DomainException
{
    public static function forAdjustment(InventoryAdjustment $adjustment): self
    {
        $identity = $adjustment->adjustment_number ?? '#'.$adjustment->id;

        return new self(sprintf(
            'The user who created inventory adjustment %s cannot confirm it.',
            $identity,
        ));
    }

    public static function forInventoryCount(InventoryCount $count): self
    {
        $identity = $count->count_number ?? '#'.$count->id;

        return new self(sprintf(
            'The user who counted inventory count %s cannot confirm it.',
            $identity,
        ));
    }
}
