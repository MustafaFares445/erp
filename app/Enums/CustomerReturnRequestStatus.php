<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomerReturnRequestStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Converted = 'converted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('admin.crm.customer_return_request_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Submitted => 'gray',
            self::UnderReview => 'warning',
            self::Approved => 'info',
            self::Rejected, self::Cancelled => 'danger',
            self::Converted => 'success',
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Rejected, self::Converted, self::Cancelled => true,
            self::Submitted, self::UnderReview, self::Approved => false,
        };
    }
}
