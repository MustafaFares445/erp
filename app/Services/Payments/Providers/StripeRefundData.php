<?php

declare(strict_types=1);

namespace App\Services\Payments\Providers;

final readonly class StripeRefundData
{
    public function __construct(
        public string $id,
        public string $status,
        public int $amountMinor,
    ) {}

    public function isSucceeded(): bool
    {
        return $this->status === 'succeeded';
    }
}
