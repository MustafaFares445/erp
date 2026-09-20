<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Crm\CustomerApprovalService;
use App\Services\Inventory\PriceResolver;

/**
 * A customer profile's review lifecycle.
 *
 * Transitions are enforced by {@see CustomerApprovalService},
 * not here — this enum only carries display metadata, mirroring the
 * {@see QuotationStatus} convention already used throughout the codebase.
 *
 * `is_active` remains the field every pricing/commercial rule already reads
 * ({@see PriceResolver}); this enum is kept in sync
 * with it rather than replacing it: `Approved` implies `is_active = true`,
 * every other case implies `is_active = false`.
 */
enum CustomerApprovalStatus: string
{
    case Pending = 'pending';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('admin.crm.customer_approval_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::ChangesRequested => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
