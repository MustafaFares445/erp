<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ShipmentArrivalConfirmation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ShipmentCustomerConfirmed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public ShipmentArrivalConfirmation $confirmation,
    ) {}
}
