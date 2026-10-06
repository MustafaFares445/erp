<?php

declare(strict_types=1);

namespace App\Services\Purchasing\Exceptions;

use App\Models\ProductVariant;
use App\Models\Supplier;
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

    public static function invalidPurchaseUnit(ProductVariant $variant): self
    {
        return new self(sprintf(
            'The selected unit is not an active purchase UOM for [%s].',
            $variant->sku,
        ));
    }

    public static function supplierPurchaseUnitMismatch(ProductVariant $variant): self
    {
        return new self(sprintf(
            'The selected unit does not match the supplier purchase UOM configured for [%s].',
            $variant->sku,
        ));
    }

    public static function minimumOrderQuantity(ProductVariant $variant, string $minimum): self
    {
        return new self(sprintf(
            'Supplier minimum order quantity for [%s] is %s in the configured purchase UOM.',
            $variant->sku,
            $minimum,
        ));
    }

    public static function unsupportedSupplierItem(Supplier $supplier, ProductVariant $variant): self
    {
        return new self(sprintf(
            'Supplier [%s] does not have an active product reference for variant [%s].',
            $supplier->name,
            $variant->sku,
        ));
    }

    public static function supplierChangeRequiresEmptyOrder(): self
    {
        return new self('Remove all purchase-order lines before changing the supplier.');
    }

    public static function currencyChangeRequiresEmptyOrder(): self
    {
        return new self('Remove all purchase-order lines before changing the Purchase Order currency.');
    }

    public static function supplierReferenceCurrencyMismatch(string $referenceCurrency, string $orderCurrency): self
    {
        return new self(sprintf(
            'Supplier reference cost is in %s while the purchase order is in %s. Enter the negotiated %s unit price manually.',
            mb_strtoupper($referenceCurrency),
            mb_strtoupper($orderCurrency),
            mb_strtoupper($orderCurrency),
        ));
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

    public static function noLines(string $orderNumber): self
    {
        return new self(__('admin.purchasing.errors.no_lines', [
            'order' => $orderNumber,
        ]));
    }
}
