<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\OrderAvailabilityState;
use App\Enums\OrderStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\SalesProcurementRequirement;
use App\Models\SupplierConfirmation;
use Illuminate\Database\Eloquent\Collection;

final class OrderAvailabilityService
{
    private const float TOLERANCE = 0.000001;

    /**
     * @param  Collection<int, SalesProcurementRequirement>  $requirements
     * @param  array<string, float>  $facts
     */
    public function resolve(Order $order, Collection $requirements, array $facts): OrderAvailabilityState
    {
        if ($order->status === OrderStatus::Cancelled) {
            return OrderAvailabilityState::Cancelled;
        }

        if (in_array($order->status, [OrderStatus::Draft, OrderStatus::Confirmed], true)) {
            return OrderAvailabilityState::CommercialPending;
        }

        $open = $requirements
            ->reject(static fn (SalesProcurementRequirement $requirement): bool => $requirement->isFulfilled()
                || in_array($requirement->status, ['fulfilled', 'cancelled', 'superseded'], true))
            ->values();

        if (($facts['procurement_outstanding'] ?? 0.0) > self::TOLERANCE || $open->isNotEmpty()) {
            if ($this->hasFulfillmentProgress($facts)) {
                return OrderAvailabilityState::PartiallyAvailable;
            }

            if ($open->contains(static fn (SalesProcurementRequirement $requirement): bool => $requirement->purchase_order_id === null)) {
                return OrderAvailabilityState::InsufficientStock;
            }

            if ($open->contains(fn (SalesProcurementRequirement $requirement): bool => $this->awaitingSupplierConfirmation($requirement))) {
                return OrderAvailabilityState::AwaitingSupplierConfirmation;
            }

            return OrderAvailabilityState::AwaitingPurchase;
        }

        if (($facts['dispatched'] ?? 0.0) > ($facts['arrived'] ?? 0.0) + self::TOLERANCE) {
            return OrderAvailabilityState::InTransit;
        }

        if (($facts['ready'] ?? 0.0) > self::TOLERANCE) {
            return OrderAvailabilityState::ReadyForDelivery;
        }

        if (($facts['remaining'] ?? 0.0) > self::TOLERANCE) {
            if (($facts['planned'] ?? 0.0) > self::TOLERANCE) {
                return OrderAvailabilityState::PartiallyAvailable;
            }

            return OrderAvailabilityState::AvailableForAllocation;
        }

        return OrderAvailabilityState::Fulfilled;
    }

    /** @param array<string, float> $facts */
    private function hasFulfillmentProgress(array $facts): bool
    {
        return ($facts['planned'] ?? 0.0) > self::TOLERANCE
            || ($facts['ready'] ?? 0.0) > self::TOLERANCE
            || ($facts['dispatched'] ?? 0.0) > self::TOLERANCE
            || ($facts['arrived'] ?? 0.0) > self::TOLERANCE;
    }

    private function awaitingSupplierConfirmation(SalesProcurementRequirement $requirement): bool
    {
        $purchaseOrder = $requirement->relationLoaded('purchaseOrder')
            ? $requirement->purchaseOrder
            : $requirement->purchaseOrder()->with('confirmations')->first();

        if (! $purchaseOrder instanceof PurchaseOrder
            || ! in_array($purchaseOrder->status, [PurchaseOrderStatus::Accepted, PurchaseOrderStatus::PartiallyReceived], true)
            || $purchaseOrder->sent_at === null
            || ! $purchaseOrder->supplier_confirmation_required) {
            return false;
        }

        if (! $purchaseOrder->relationLoaded('confirmations')) {
            $purchaseOrder->load('confirmations');
        }

        $latest = $purchaseOrder->confirmations->sortByDesc('id')->first();

        return ! $latest instanceof SupplierConfirmation
            || $latest->confirmation_status === SupplierConfirmationStatus::Pending;
    }
}
