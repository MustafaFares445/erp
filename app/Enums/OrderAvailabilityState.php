<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderAvailabilityState: string
{
    case CommercialPending = 'commercial_pending';
    case InsufficientStock = 'insufficient_stock';
    case AwaitingPurchase = 'awaiting_purchase';
    case AwaitingSupplierConfirmation = 'awaiting_supplier_confirmation';
    case PartiallyAvailable = 'partially_available';
    case AvailableForAllocation = 'available_for_allocation';
    case ReadyForDelivery = 'ready_for_delivery';
    case InTransit = 'in_transit';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::CommercialPending => 'Commercial approval pending',
            self::InsufficientStock => 'Insufficient Stock',
            self::AwaitingPurchase => 'Awaiting Purchase',
            self::AwaitingSupplierConfirmation => 'Awaiting Supplier Confirmation',
            self::PartiallyAvailable => 'Partially Available',
            self::AvailableForAllocation => 'Available for Allocation',
            self::ReadyForDelivery => 'Ready for Delivery',
            self::InTransit => 'In Transit',
            self::Fulfilled => 'Fulfilled',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Cancelled => 'danger',
            self::InsufficientStock => 'danger',
            self::AwaitingPurchase, self::AwaitingSupplierConfirmation => 'warning',
            self::PartiallyAvailable, self::AvailableForAllocation, self::InTransit => 'info',
            self::ReadyForDelivery, self::Fulfilled => 'success',
            self::CommercialPending => 'gray',
        };
    }
}
