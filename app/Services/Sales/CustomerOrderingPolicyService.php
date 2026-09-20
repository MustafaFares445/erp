<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\CustomerApprovalStatus;
use App\Models\CustomerProfile;

/**
 * Answers the two commercial-capability questions the future customer-facing
 * channels (dashboard "create on behalf of" flows today, mobile API later)
 * need before letting a customer request a quotation or place a direct
 * order. Kept as a single reusable helper rather than re-deriving these
 * checks per caller (§18 of the Customer App V1 plan).
 *
 * Neither method bypasses {@see QuotationConversionService}
 * or an order-fulfillment service — this is a read-only capability check.
 */
final readonly class CustomerOrderingPolicyService
{
    public function canRequestQuotation(CustomerProfile $customer): bool
    {
        return $customer->is_active && $customer->approval_status === CustomerApprovalStatus::Approved;
    }

    public function canDirectOrder(CustomerProfile $customer): bool
    {
        return $this->canRequestQuotation($customer) && $customer->allow_direct_orders;
    }
}
