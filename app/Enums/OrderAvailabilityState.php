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
            self::CommercialPending => __('Commercial approval pending'),
            self::InsufficientStock => __('Insufficient Stock'),
            self::AwaitingPurchase => __('Awaiting Purchase'),
            self::AwaitingSupplierConfirmation => __('Awaiting Supplier Confirmation'),
            self::PartiallyAvailable => __('Partially Available'),
            self::AvailableForAllocation => __('Available for Allocation'),
            self::ReadyForDelivery => __('Ready for Delivery'),
            self::InTransit => __('In Transit'),
            self::Fulfilled => __('Fulfilled'),
            self::Cancelled => __('Cancelled'),
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
