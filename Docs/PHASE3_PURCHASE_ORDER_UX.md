# Phase 3 — Purchase Order UX

## Purpose

Phase 3 makes the Purchase Order drafting experience follow the canonical cross-module ownership established in Phases 0–2.

The buyer must be able to see what the selected supplier actually supports and what stock exists across warehouses without making the Purchase Order own warehouse allocation.

## Canonical rules

### Supplier-scoped item catalogue

A Purchase Order line may only reference a product variant that has an **active `SupplierProductReference` for the order's supplier**.

The same rule is enforced twice:

1. Filament filters the item picker to the active supplier catalogue.
2. `PurchaseOrderService` rejects unsupported variants even when the UI is bypassed.

An inactive or soft-deleted supplier product reference is treated as unsupported.

The earlier Purchasing feature specification allowed a variant without a supplier reference to fall back to zero cost. Phase 3 intentionally supersedes that behavior as part of the cross-module remediation: an unsupported item is now rejected instead of becoming an unproven commercial line.

### Pricing

For a supported variant:

- the supplier product reference remains the price provenance;
- the purchase UOM conversion still scales the default reference cost;
- an authorized buyer may still override the unit cost manually;
- the line still snapshots `supplier_product_reference_id` and `supplier_item_number`.

A manual price does **not** bypass the requirement for an active supplier product reference.

### Supplier changes on a populated draft

Once a draft contains lines, its supplier cannot be changed. The buyer must remove the lines first.

This prevents the order from retaining product-reference provenance from one supplier while its header points to another supplier.

### Warehouse availability matrix

The Purchase Order detail view exposes a read-only matrix for every ordered variant across active warehouses:

- SKU;
- warehouse;
- on-hand quantity;
- reserved quantity;
- saleable available quantity;
- internal-transfer quantity in transit;
- projected quantity.

Inventory remains the owner of every value in this matrix. `PurchaseOrderWarehouseAvailabilityService` reads `InventoryStock` and uses `ReplenishmentProjectionService` when a warehouse replenishment policy exists.

The matrix does not write an allocation and does not add `warehouse_id` to `purchase_orders` or `purchase_order_lines`.

## Phase boundary

Phase 3 does **not** implement multi-warehouse inbound allocation. One inbound line to many warehouse allocations belongs to Phase 4.

No database migration is required for Phase 3.
