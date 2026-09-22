<?php

declare(strict_types=1);

namespace App\Data\Settings;

use Spatie\LaravelData\Data;

/**
 * The purchasing auto-approval threshold, as the constraint registry reports
 * it.
 *
 * The amount stays in `purchase_settings`; this is the read-through shape so
 * callers reach every business limit through one API. The currency travels
 * with the amount because the threshold refuses cross-currency comparison
 * rather than converting, so an amount without its currency is not a usable
 * answer.
 */
final class PurchaseApprovalThresholdData extends Data
{
    public function __construct(
        public float $amount,
        public string $currency,
    ) {}

    /** A threshold of zero routes every order to explicit approval. */
    public function isDisabled(): bool
    {
        return $this->amount <= 0.0;
    }
}
