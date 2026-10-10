---
title: "Inventory Transfer"
type: workflow
lastVerified: 2026-10-07
---

# Inventory Transfer

Move stock between warehouse locations while preserving source, destination and tracking identity.

<IerpWorkflow :steps='["Transfer", "Source Stock", "Tracked Allocation", "Movement", "Destination Stock"]' />

## Modules involved

Inventory.

## When to use this workflow

Use this workflow when you need to complete **Inventory Transfer** end to end while preserving the responsibility boundaries between modules.

## Before you start

- Confirm required master records such as customer, supplier, product or equipment are active.
- Confirm your role has permission for the high-impact actions in each module.
- Review currency, UOM, warehouse and payment-term context when they apply.
- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.

## Step by step

### 1. Transfer

Execute the Inventory-owned receipt/delivery/transfer and post the physical movement only when validated. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Transfer](/screens/inventory/inventory-operation)

Execute controlled receipts, deliveries and internal transfers while preserving source document, tracking and movement history.

### 2. Source Stock

Review current on-hand/reserved/available stock; do not edit balances from this read-only planning surface. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Source Stock](/screens/inventory/stock-level)

Review on-hand, reserved and available quantity by warehouse/product without directly editing stock balances.

### 3. Tracked Allocation

Execute the Inventory-owned receipt/delivery/transfer and post the physical movement only when validated. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Tracked Allocation](/screens/inventory/inventory-operation)

Execute controlled receipts, deliveries and internal transfers while preserving source document, tracking and movement history.

### 4. Movement

Use the movement trail to verify what physically changed, where and from which source document. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Movement](/screens/inventory/stock-movement)

Inspect the immutable inventory movement trail to understand why, where and from which source document stock changed.

### 5. Destination Stock

Review current on-hand/reserved/available stock; do not edit balances from this read-only planning surface. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Destination Stock](/screens/inventory/stock-level)

Review on-hand, reserved and available quantity by warehouse/product without directly editing stock balances.

## Important business rules

- Lot and serial identity must remain consistent.
- Transfers are inventory movements, not purchasing or sales transactions.

## Exceptions and blocked states

- If the next action is missing, verify permissions, status and prerequisites.
- Do not skip a step by forcing a status; use the dedicated workflow action.
- Shortage, partial confirmation, partial payment or unfinished service work keeps the process in follow-up instead of pretending it is complete.
- Corrections after posting use correction/reversal paths instead of deleting history.

## Related screens

- [Inventory](/screens/inventory/)

## Expected result

The workflow is complete when every step reaches its correct terminal state and downstream effects are visible in their owning modules with no open child work blocking completion.
