<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\InventoryStock;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class StockLow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public InventoryStock $stock,
    ) {}
}
