<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Invoice;
use App\Models\PaymentAllocation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CustomerDepositApplied
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public PaymentAllocation $allocation,
    ) {}
}
