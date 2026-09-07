<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\InventoryReservation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

final class InventoryReservationExpired
{
    use Dispatchable;

    public function __construct(
        public InventoryReservation $reservation,
        public ?Model $sourceDocument = null,
    ) {}
}
