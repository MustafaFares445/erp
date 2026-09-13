<?php

declare(strict_types=1);

namespace App\Services\Purchasing\Exceptions;

use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\Warehouse;
use DomainException;

/**
 * Domain invariant violations for quantity-based purchase inbound allocation.
 *
 * Allocation quantities are exact base-unit decimals. The service raises named
 * failures before persistence so Filament/API callers receive actionable
 * business errors instead of database constraint or decimal coercion errors.
 */
final class InvalidPurchaseInboundAllocation extends DomainException
{
    public static function quantityNotPositive(): self
    {
        return new self('The allocated quantity must be a positive decimal with at most six decimal places.');
    }

    public static function quantityRequiredForSplit(): self
    {
        return new self('An explicit allocated quantity is required once an inbound line has multiple warehouse allocations.');
    }

    public static function inboundQuantityUnavailable(PurchaseInboundLine $line): self
    {
        return new self(sprintf(
            'Inbound line [%d] has no canonical base quantity and cannot be allocated.',
            $line->id,
        ));
    }

    public static function unresolvedHistoricalQuantity(PurchaseInboundAllocation $allocation): self
    {
        return new self(sprintf(
            'Allocation [%d] has no deterministic allocated base quantity. Resolve the historical allocation before changing this inbound line.',
            $allocation->id,
        ));
    }

    public static function duplicateWarehouse(Warehouse $warehouse): self
    {
        return new self(sprintf(
            'Warehouse [%s] is already allocated on this inbound line.',
            $warehouse->code,
        ));
    }

    public static function overAllocated(string $inboundQuantity, string $attemptedTotal): self
    {
        return new self(sprintf(
            'The allocation total [%s] exceeds the inbound base quantity [%s].',
            $attemptedTotal,
            $inboundQuantity,
        ));
    }

    public static function inactiveWarehouse(Warehouse $warehouse): self
    {
        return new self(sprintf(
            'Warehouse [%s] is inactive or unavailable for inbound allocation.',
            $warehouse->code,
        ));
    }

    public static function wrongInboundLine(PurchaseInboundAllocation $allocation, PurchaseInboundLine $line): self
    {
        return new self(sprintf(
            'Allocation [%d] does not belong to inbound line [%d].',
            $allocation->id,
            $line->id,
        ));
    }

    public static function belowCommitted(string $committedQuantity): self
    {
        return new self(sprintf(
            'The allocation cannot be reduced below the already received or reserved receipt base quantity [%s].',
            $committedQuantity,
        ));
    }

    public static function cannotMoveCommittedAllocation(): self
    {
        return new self('An allocation cannot be moved while received or active receipt quantity is committed to it.');
    }

    public static function cannotDeleteCommitted(string $committedQuantity): self
    {
        return new self(sprintf(
            'An allocation with received or active receipt base quantity [%s] cannot be deleted.',
            $committedQuantity,
        ));
    }
}
