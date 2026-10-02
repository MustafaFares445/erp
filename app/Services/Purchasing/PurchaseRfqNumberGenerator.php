<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\PurchaseRfq;

final readonly class PurchaseRfqNumberGenerator
{
    public function next(): string
    {
        $max = PurchaseRfq::query()->lockForUpdate()->max('rfq_number');
        $sequence = is_string($max) ? (int) mb_substr($max, 4) + 1 : 1;

        return sprintf('RFQ-%06d', $sequence);
    }
}
