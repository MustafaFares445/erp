<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\CustomerQuotationRequest;
use App\Models\Quotation;
use App\Services\Crm\CustomerQuotationRequestService;
use App\Services\Inventory\PriceResolver;

/**
 * Lifecycle of a {@see CustomerQuotationRequest} — a customer's
 * pre-quotation request for products/variants/quantities. It never carries a
 * client-trusted price; only converting to a {@see Quotation}
 * (via {@see CustomerQuotationRequestService::convertToQuotation()})
 * resolves a real, current price through {@see PriceResolver}.
 */
enum CustomerQuotationRequestStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Quoted = 'quoted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('admin.crm.customer_quotation_request_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Submitted => 'info',
            self::UnderReview => 'warning',
            self::Quoted => 'success',
            self::Rejected => 'danger',
            self::Cancelled => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return match ($this) {
            self::Submitted, self::UnderReview => true,
            default => false,
        };
    }
}
