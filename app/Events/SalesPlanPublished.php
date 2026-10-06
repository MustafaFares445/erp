<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\SalesPlan;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SalesPlanPublished
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public SalesPlan $plan) {}
}
