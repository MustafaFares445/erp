<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\CustomerProfileChangeRequest;
use App\Services\Crm\CustomerProfileChangeRequestService;

/**
 * Lifecycle of a {@see CustomerProfileChangeRequest} — a proposal
 * to change a customer's legal/company identity fields or replace a legal
 * document, reviewed by an admin before it ever touches the CustomerProfile.
 *
 * Transitions are enforced by
 * {@see CustomerProfileChangeRequestService}, not here.
 */
enum CustomerProfileChangeRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('admin.crm.customer_profile_change_request_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Cancelled => 'gray',
        };
    }

    public function isDecided(): bool
    {
        return $this !== self::Pending;
    }
}
