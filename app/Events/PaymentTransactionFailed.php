<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PaymentTransaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class PaymentTransactionFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public PaymentTransaction $transaction,
    ) {}
}
