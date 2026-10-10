---
title: "Warehouse Replenishment"
type: workflow
lastVerified: 2026-10-07
---

# Warehouse Replenishment

Turn min/max replenishment policy and stock alerts into controlled purchase needs and supplier orders.

<IerpWorkflow :steps='["Replenishment Policy", "Stock Position", "Alert / Recommendation", "Purchase Need", "Purchase Order", "Receipt"]' />

## Modules involved

Inventory, Purchasing.

## When to use this workflow

Use this workflow when you need to complete **Warehouse Replenishment** end to end while preserving the responsibility boundaries between modules.

## Before you start

- Confirm required master records such as customer, supplier, product or equipment are active.
- Confirm your role has permission for the high-impact actions in each module.
- Review currency, UOM, warehouse and payment-term context when they apply.
- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.

## Step by step

### 1. Replenishment Policy

Review the configured min/max, supplier and lead-time policy used to calculate replenishment need. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Replenishment Policy](/screens/inventory/warehouse-replenishment-policy)

Define warehouse/product replenishment policy such as minimum/maximum targets and preferred supply behavior used to generate recommendations.

### 2. Stock Position

Review current on-hand/reserved/available stock; do not edit balances from this read-only planning surface. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Stock Position](/screens/inventory/stock-level)

Review on-hand, reserved and available quantity by warehouse/product without directly editing stock balances.

### 3. Alert / Recommendation

Review the exception/alert and decide whether it requires replenishment or another owning-domain action. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Alert / Recommendation](/screens/inventory/inventory-alert)

Work inventory exception and planning alerts such as low stock or replenishment need, then open the owning action to resolve them.

### 4. Purchase Need

Review the external supply requirement and preserve its source, quantity and planning context. Verify supplier, purchasing UOM, currency and source demand. Purchasing owns supplier commitment, not physical stock posting.

**Work in:** [Purchase Need](/screens/vendors/purchase-needs)

Review internal demand that requires external purchasing and turn eligible need into RFQ/PO sourcing without losing source provenance.

### 5. Purchase Order

Create, approve and send the supplier commitment using supplier-owned commercial data. Verify supplier, purchasing UOM, currency and source demand. Purchasing owns supplier commitment, not physical stock posting.

**Work in:** [Purchase Order](/screens/vendors/purchase-order)

Create, approve and send supplier commitments, then follow confirmation, inbound allocation, physical receipt and billing progress.

### 6. Receipt

Execute the Inventory-owned receipt/delivery/transfer and post the physical movement only when validated. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Receipt](/screens/inventory/inventory-operation)

Execute controlled receipts, deliveries and internal transfers while preserving source document, tracking and movement history.

## Important business rules

- Reservations are not double-subtracted when calculating shortage.
- Preferred supplier, purchase UOM, MOQ and lead time can influence the recommendation.
- Physical receipt remains an Inventory action.

## Exceptions and blocked states

- If the next action is missing, verify permissions, status and prerequisites.
- Do not skip a step by forcing a status; use the dedicated workflow action.
- Shortage, partial confirmation, partial payment or unfinished service work keeps the process in follow-up instead of pretending it is complete.
- Corrections after posting use correction/reversal paths instead of deleting history.

## Related screens

- [Inventory](/screens/inventory/)
- [Purchasing](/screens/vendors/)

## Expected result

The workflow is complete when every step reaches its correct terminal state and downstream effects are visible in their owning modules with no open child work blocking completion.
