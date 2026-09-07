<?php

declare(strict_types=1);

namespace App\Data\Accounting;

use App\Enums\WriteOffReason;

final readonly class WriteOffData
{
    public function __construct(
        public int $customerId,
        public int $invoiceId,
        public int $amountMinor,
        public WriteOffReason $reasonCategory,
        public string $reason,
    ) {}
}
