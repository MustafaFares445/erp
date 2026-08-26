<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Quotation;

/**
 * The lifecycle of a {@see Quotation} (FR-019,
 * contracts/lifecycles.md §1).
 *
 * `Expired` is both stored and derived: stored when a decision is attempted
 * past `expires_at`, and derived for display on any `Sent` quotation whose
 * expiry has already passed. No scheduled command sweeps for expiry.
 */
enum QuotationStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case ConvertedToDelivery = 'converted_to_delivery';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Rejected, self::Expired, self::ConvertedToDelivery, self::Cancelled => true,
            default => false,
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => in_array($target, [self::Sent, self::Cancelled], true),
            self::Sent => in_array($target, [self::Accepted, self::Rejected, self::Expired, self::Cancelled], true),
            self::Accepted => in_array($target, [self::ConvertedToDelivery, self::Cancelled], true),
            self::Rejected, self::Expired, self::ConvertedToDelivery, self::Cancelled => false,
        };
    }

    public function label(): string
    {
        return __('admin.sales.quotation_status.'.$this->value);
    }
}
