<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Quotation;
use App\Models\QuotationResponse;

/**
 * The kind of customer decision recorded by a
 * {@see QuotationResponse} — append-only evidence, independent
 * of the mutable projection fields kept on {@see Quotation}
 * itself for backward-compatible display.
 */
enum QuotationResponseType: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case ChangesRequested = 'changes_requested';

    public function label(): string
    {
        return __('admin.sales.quotation_response_type.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Accepted => 'success',
            self::Rejected => 'danger',
            self::ChangesRequested => 'warning',
        };
    }
}
