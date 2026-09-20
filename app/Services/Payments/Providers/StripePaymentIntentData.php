<?php

declare(strict_types=1);

namespace App\Services\Payments\Providers;

final readonly class StripePaymentIntentData
{
    public function __construct(
        public string $id,
        public string $status,
        public int $amountMinor,
        public string $currency,
        public ?string $latestChargeId,
        public ?string $lastEventId = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
    ) {}

    public function isSucceeded(): bool
    {
        return $this->status === 'succeeded';
    }
}
