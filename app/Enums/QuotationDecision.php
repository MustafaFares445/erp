<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The outcome an admin or employee records on the customer's behalf
 * (FR-021, owner decision D8 — there is no customer-facing accept/reject
 * route).
 */
enum QuotationDecision: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function resultingStatus(): QuotationStatus
    {
        return match ($this) {
            self::Accepted => QuotationStatus::Accepted,
            self::Rejected => QuotationStatus::Rejected,
        };
    }

    public function label(): string
    {
        return __('admin.sales.quotation_decision.'.$this->value);
    }
}
