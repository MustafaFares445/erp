<?php

declare(strict_types=1);

namespace App\Services\Payments\Providers;

final readonly class StripeCheckoutSessionData
{
    public function __construct(
        public string $id,
        public ?string $url,
        public ?string $paymentIntentId,
        public string $status,
    ) {}
}
