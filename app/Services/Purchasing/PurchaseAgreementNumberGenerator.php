<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\PurchaseAgreement;

final readonly class PurchaseAgreementNumberGenerator
{
    public function next(): string
    {
        $max = PurchaseAgreement::query()->lockForUpdate()->max('agreement_number');
        $sequence = is_string($max) ? (int) mb_substr($max, 4) + 1 : 1;

        return sprintf('AGR-%06d', $sequence);
    }
}
