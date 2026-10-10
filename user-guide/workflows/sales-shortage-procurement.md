---
title: "Sales Shortage Procurement"
type: workflow
lastVerified: 2026-10-07
---

# Sales Shortage Procurement

Cover a Sales Order shortage through purchasing and bring received stock back into fulfillment readiness.

<IerpWorkflow :steps='["Sales Order", "Shortage", "Purchase Need", "Purchase Order", "Supplier Confirmation", "Receipt", "Reservation", "Fulfillment"]' />

## Modules involved

Sales, Purchasing, Inventory.

## When to use this workflow

Use this workflow when you need to complete **Sales Shortage Procurement** end to end while preserving the responsibility boundaries between modules.

## Before you start

- Confirm required master records such as customer, supplier, product or equipment are active.
- Confirm your role has permission for the high-impact actions in each module.
- Review currency, UOM, warehouse and payment-term context when they apply.
- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.

## Step by step

### 1. Sales Order

Confirm customer demand and use the order lifecycle to control release into fulfillment/procurement. Verify the commercial source/status, customer and line values. Sales owns the customer document; physical stock still changes only through Inventory.

**Work in:** [Sales Order](/screens/sales/order)

Manage confirmed customer demand, availability, procurement coverage and the release boundary into fulfillment.

### 2. Shortage

Review current on-hand/reserved/available stock; do not edit balances from this read-only planning surface. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Shortage](/screens/inventory/stock-level)

Review on-hand, reserved and available quantity by warehouse/product without directly editing stock balances.

### 3. Purchase Need

Review the external supply requirement and preserve its source, quantity and planning context. Verify supplier, purchasing UOM, currency and source demand. Purchasing owns supplier commitment, not physical stock posting.

**Work in:** [Purchase Need](/screens/vendors/purchase-needs)

Review internal demand that requires external purchasing and turn eligible need into RFQ/PO sourcing without losing source provenance.

### 4. Purchase Order

Create, approve and send the supplier commitment using supplier-owned commercial data. Verify supplier, purchasing UOM, currency and source demand. Purchasing owns supplier commitment, not physical stock posting.

**Work in:** [Purchase Order](/screens/vendors/purchase-order)

Create, approve and send supplier commitments, then follow confirmation, inbound allocation, physical receipt and billing progress.

### 5. Supplier Confirmation

Record supplier-committed quantity and promised dates, including valid partial commitments. Verify supplier, purchasing UOM, currency and source demand. Purchasing owns supplier commitment, not physical stock posting.

**Work in:** [Supplier Confirmation](/screens/vendors/supplier-confirmation)

Record supplier-confirmed quantities and promised dates against a sent Purchase Order and expose partial/changed commitment clearly.

### 6. Receipt

Execute the Inventory-owned receipt/delivery/transfer and post the physical movement only when validated. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Receipt](/screens/inventory/inventory-operation)

Execute controlled receipts, deliveries and internal transfers while preserving source document, tracking and movement history.

### 7. Reservation

Reserve eligible available stock for the source demand while preserving warehouse/tracking provenance. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Reservation](/screens/inventory/inventory-reservation)

Review stock reserved for downstream demand and understand which source record currently claims available quantity.

### 8. Fulfillment

Plan and execute the warehouse-facing fulfillment work against actually available/reserved stock. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Fulfillment](/screens/inventory/outbound-fulfillment)

Plan and execute outbound customer fulfillment against released demand before delivery stock posting and shipment progress.

## Important business rules

- Order availability states explain readiness but do not replace Inventory posting.
- Procurement requirements preserve their Sales Order provenance.
- Received stock must become available before fulfillment can consume it.

## Exceptions and blocked states

- If the next action is missing, verify permissions, status and prerequisites.
- Do not skip a step by forcing a status; use the dedicated workflow action.
- Shortage, partial confirmation, partial payment or unfinished service work keeps the process in follow-up instead of pretending it is complete.
- Corrections after posting use correction/reversal paths instead of deleting history.

## Related screens

- [Sales](/screens/sales/)
- [Purchasing](/screens/vendors/)
- [Inventory](/screens/inventory/)

## Expected result

The workflow is complete when every step reaches its correct terminal state and downstream effects are visible in their owning modules with no open child work blocking completion.
