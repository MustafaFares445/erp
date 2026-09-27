<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\OrderCloseSource;
use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final readonly class OrderClosed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Order $order, public OrderCloseSource $source) {}
}
