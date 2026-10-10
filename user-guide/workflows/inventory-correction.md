---
title: "Inventory Correction"
type: workflow
lastVerified: 2026-10-07
---

# Inventory Correction

Correct a previously posted inventory operation with an explicit historical correction instead of rewriting the original movement.

<IerpWorkflow :steps='["Posted Operation", "Correction Reason", "Correction Lines", "Review", "Compensating Movement"]' />

## Modules involved

Inventory.

## When to use this workflow

Use this workflow when you need to complete **Inventory Correction** end to end while preserving the responsibility boundaries between modules.

## Before you start

- Confirm required master records such as customer, supplier, product or equipment are active.
- Confirm your role has permission for the high-impact actions in each module.
- Review currency, UOM, warehouse and payment-term context when they apply.
- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.

## Step by step

### 1. Posted Operation

Execute the Inventory-owned receipt/delivery/transfer and post the physical movement only when validated. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Posted Operation](/screens/inventory/inventory-operation)

Execute controlled receipts, deliveries and internal transfers while preserving source document, tracking and movement history.

### 2. Correction Reason

Create the explicit correction against posted inventory history so the original movement remains visible. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Correction Reason](/screens/inventory/inventory-correction)

Correct previously posted inventory history through an explicit compensating/correction flow instead of rewriting the original movement.

### 3. Correction Lines

Create the explicit correction against posted inventory history so the original movement remains visible. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Correction Lines](/screens/inventory/inventory-correction)

Correct previously posted inventory history through an explicit compensating/correction flow instead of rewriting the original movement.

### 4. Review

Create the explicit correction against posted inventory history so the original movement remains visible. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Review](/screens/inventory/inventory-correction)

Correct previously posted inventory history through an explicit compensating/correction flow instead of rewriting the original movement.

### 5. Compensating Movement

Use the movement trail to verify what physically changed, where and from which source document. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Compensating Movement](/screens/inventory/stock-movement)

Inspect the immutable inventory movement trail to understand why, where and from which source document stock changed.

## Important business rules

- Posted history remains visible.
- Inventory correction never fabricates a Credit Note or Refund; financial consequences stay in Sales/Accounting.

## Exceptions and blocked states

- If the next action is missing, verify permissions, status and prerequisites.
- Do not skip a step by forcing a status; use the dedicated workflow action.
- Shortage, partial confirmation, partial payment or unfinished service work keeps the process in follow-up instead of pretending it is complete.
- Corrections after posting use correction/reversal paths instead of deleting history.

## Related screens

- [Inventory](/screens/inventory/)

## Expected result

The workflow is complete when every step reaches its correct terminal state and downstream effects are visible in their owning modules with no open child work blocking completion.
