---
title: "Tracked Inventory Lifecycle"
type: workflow
lastVerified: 2026-10-07
---

# Tracked Inventory Lifecycle

Follow lot-, serial- and expiry-controlled inventory from receipt through reservation, delivery and customer custody.

<IerpWorkflow :steps='["Tracked Product", "Receipt", "Lot / Serial", "Stock", "Reservation", "Picking", "Delivery", "Custody"]' />

## Modules involved

Inventory, Sales, Support and Maintenance.

## When to use this workflow

Use this workflow when you need to complete **Tracked Inventory Lifecycle** end to end while preserving the responsibility boundaries between modules.

## Before you start

- Confirm required master records such as customer, supplier, product or equipment are active.
- Confirm your role has permission for the high-impact actions in each module.
- Review currency, UOM, warehouse and payment-term context when they apply.
- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.

## Step by step

### 1. Tracked Product

Verify catalog/variant/UOM/tracking configuration before relying on the item in downstream operations. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Tracked Product](/screens/inventory/product)

Maintain catalog product identity, classification and shared attributes while keeping variant, supplier and physical-stock facts separate.

### 2. Receipt

Execute the Inventory-owned receipt/delivery/transfer and post the physical movement only when validated. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Receipt](/screens/inventory/inventory-operation)

Execute controlled receipts, deliveries and internal transfers while preserving source document, tracking and movement history.

### 3. Lot / Serial

Verify lot identity, quantity and expiry-sensitive context for the tracked stock. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Lot / Serial](/screens/inventory/inventory-lot)

Track lot-controlled stock, expiry, condition and movement provenance for products that require batch identity.

### 4. Stock

Review current on-hand/reserved/available stock; do not edit balances from this read-only planning surface. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Stock](/screens/inventory/stock-level)

Review on-hand, reserved and available quantity by warehouse/product without directly editing stock balances.

### 5. Reservation

Reserve eligible available stock for the source demand while preserving warehouse/tracking provenance. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Reservation](/screens/inventory/inventory-reservation)

Review stock reserved for downstream demand and understand which source record currently claims available quantity.

### 6. Picking

Plan and execute the warehouse-facing fulfillment work against actually available/reserved stock. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Picking](/screens/inventory/outbound-fulfillment)

Plan and execute outbound customer fulfillment against released demand before delivery stock posting and shipment progress.

### 7. Delivery

Execute the Inventory-owned receipt/delivery/transfer and post the physical movement only when validated. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Delivery](/screens/inventory/inventory-operation)

Execute controlled receipts, deliveries and internal transfers while preserving source document, tracking and movement history.

### 8. Custody

Trace the unique serial, condition and custody history for this physical unit. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Custody](/screens/inventory/serialized-inventory-unit)

Track each serialized unit as a unique physical identity through warehouse custody, delivery, customer ownership and service history.

## Important business rules

- Expiry-aware stock can use FEFO.
- Serial identity is unique and custody history must remain traceable.
- Support equipment should reuse known customer-owned serialized equipment when available.

## Exceptions and blocked states

- If the next action is missing, verify permissions, status and prerequisites.
- Do not skip a step by forcing a status; use the dedicated workflow action.
- Shortage, partial confirmation, partial payment or unfinished service work keeps the process in follow-up instead of pretending it is complete.
- Corrections after posting use correction/reversal paths instead of deleting history.

## Related screens

- [Inventory](/screens/inventory/)
- [Sales](/screens/sales/)
- [Support and Maintenance](/screens/support/)

## Expected result

The workflow is complete when every step reaches its correct terminal state and downstream effects are visible in their owning modules with no open child work blocking completion.
