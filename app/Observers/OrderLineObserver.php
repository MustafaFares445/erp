<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\OrderLine;
use App\Services\Sales\DirectOrderLinePricingService;

final readonly class OrderLineObserver
{
    public function __construct(private DirectOrderLinePricingService $pricing) {}

    public function creating(OrderLine $line): void
    {
        $this->pricing->prepare($line);
    }

    public function created(OrderLine $line): void
    {
        $this->pricing->refreshOrderTotals($line);
    }
}
