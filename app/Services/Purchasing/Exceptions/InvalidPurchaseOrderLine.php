<?php

declare(strict_types=1);

namespace App\Services\Purchasing\Exceptions;

use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\Warehouse;
use DomainException;

/**
 * Line- and header-level validation failures on a draft order (V-01, V-02,
 * V-04, V-05).
 *
 * Each named constructor produces a message that identifies the offending
 * record, because "invalid quantity" on a fifteen-line order tells the buyer
 * nothing about which line to fix.
 */
final class InvalidPurchaseOrderLine extends DomainException
{
    public static function duplicateVariant(ProductVariant $variant): self
    {
        return new self(__('admin.purchasing.errors.duplicate_line', [
            'variant' => $variant->sku,
        ]));
    }

    public static function quantityNotPositive(): self
    {
        return new self(__('admin.purchasing.errors.invalid_quantity'));
    }

    public static function unitCostNegative(): self
    {
        return new self(__('admin.purchasing.errors.invalid_unit_cost'));
    }

    public static function inactiveSupplier(Supplier $supplier): self
    {
        return new self(__('admin.purchasing.errors.inactive_supplier', [
            'supplier' => $supplier->name,
        ]));
    }

    public static function inactiveWarehouse(Warehouse $warehouse): self
    {
        return new self(__('admin.purchasing.errors.inactive_warehouse', [
            'warehouse' => $warehouse->name,
        ]));
    }

    public static function noLines(string $orderNumber): self
    {
        return new self(__('admin.purchasing.errors.no_lines', [
            'order' => $orderNumber,
        ]));
    }
}
