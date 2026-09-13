<?php

declare(strict_types=1);

namespace App\Services\Purchasing\Exceptions;

use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use DomainException;

/**
 * Domain failures for purchase receipts that consume warehouse allocations.
 */
final class InvalidPurchaseInboundReceipt extends DomainException
{
    public static function quantityNotPositive(): self
    {
        return new self('The receipt quantity must be a positive base-unit decimal with at most six decimal places.');
    }

    public static function duplicateAllocation(int $allocationId): self
    {
        return new self(sprintf('Allocation [%d] may appear only once in a purchase receipt.', $allocationId));
    }

    public static function allocationNotForOrder(PurchaseInboundAllocation $allocation, PurchaseOrder $order): self
    {
        return new self(sprintf(
            'Allocation [%d] does not belong to purchase order [%s].',
            $allocation->id,
            $order->purchase_order_number,
        ));
    }

    public static function unresolvedAllocationQuantity(PurchaseInboundAllocation $allocation): self
    {
        return new self(sprintf(
            'Allocation [%d] has no deterministic allocated base quantity and cannot be received.',
            $allocation->id,
        ));
    }

    public static function inactiveWarehouse(Warehouse $warehouse): self
    {
        return new self(sprintf('Warehouse [%s] is inactive and cannot receive stock.', $warehouse->code));
    }

    public static function mixedWarehouses(): self
    {
        return new self('One inventory receipt may consume allocations from only one destination warehouse.');
    }

    public static function allocationExceeded(string $requested, string $remaining): self
    {
        return new self(sprintf(
            'Receipt base quantity [%s] exceeds the allocation quantity still available to receive [%s].',
            $requested,
            $remaining,
        ));
    }

    public static function purchaseOrderLineExceeded(string $requested, string $remaining): self
    {
        return new self(sprintf(
            'Receipt base quantity [%s] exceeds the purchase-order line quantity still available to receive [%s].',
            $requested,
            $remaining,
        ));
    }

    public static function nothingAvailable(PurchaseOrder $order): self
    {
        return new self(sprintf(
            'Purchase order [%s] has no allocated quantity currently available for a new receipt.',
            $order->purchase_order_number,
        ));
    }

    public static function missingAllocationProvenance(): self
    {
        return new self('A purchase-order receipt line requires deterministic purchase inbound allocation provenance.');
    }

    public static function warehouseMismatch(PurchaseInboundAllocation $allocation): self
    {
        return new self(sprintf(
            'Receipt destination warehouse does not match allocation [%d].',
            $allocation->id,
        ));
    }

    public static function purchaseOrderLineMismatch(PurchaseInboundAllocation $allocation): self
    {
        return new self(sprintf(
            'Receipt purchase-order line does not match allocation [%d].',
            $allocation->id,
        ));
    }

    public static function allocationOverReceived(PurchaseInboundAllocation $allocation, string $received, string $allocated): self
    {
        return new self(sprintf(
            'Allocation [%d] would have receipt quantity [%s] greater than its allocated quantity [%s].',
            $allocation->id,
            $received,
            $allocated,
        ));
    }
}
