<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Order;

/**
 * The money-state axis of an {@see Order}, independent of its
 * built `status` (fulfillment state) (FR-026, contracts/lifecycles.md §2).
 *
 * `null` is reserved for an order that predates this feature. Every other
 * value is derived from the invoices raised against the order's deliveries
 * — nothing in this feature writes it by hand, and no case here has a
 * `canTransitionTo()` because there is no user-driven transition to guard.
 */
enum OrderPaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    public function label(): string
    {
        return __('admin.sales.order_payment_status.'.$this->value);
    }
}
