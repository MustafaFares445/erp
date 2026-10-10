---
title: "Physical Count"
type: workflow
lastVerified: 2026-10-07
---

# Physical Count

Compare physical stock with system stock and post an approved variance through the controlled count workflow.

<IerpWorkflow :steps='["Count Scope", "Physical Count", "Variance", "Review", "Approval", "Adjustment"]' />

## Modules involved

Inventory.

## When to use this workflow

Use this workflow when you need to complete **Physical Count** end to end while preserving the responsibility boundaries between modules.

## Before you start

- Confirm required master records such as customer, supplier, product or equipment are active.
- Confirm your role has permission for the high-impact actions in each module.
- Review currency, UOM, warehouse and payment-term context when they apply.
- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.

## Step by step

### 1. Count Scope

Define/count/review the physical count and variance through the controlled count lifecycle. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Count Scope](/screens/inventory/inventory-count)

Run physical/cycle counts, compare counted quantity with system snapshots and turn approved variance into auditable correction.

### 2. Physical Count

Define/count/review the physical count and variance through the controlled count lifecycle. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Physical Count](/screens/inventory/inventory-count)

Run physical/cycle counts, compare counted quantity with system snapshots and turn approved variance into auditable correction.

### 3. Variance

Define/count/review the physical count and variance through the controlled count lifecycle. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Variance](/screens/inventory/inventory-count)

Run physical/cycle counts, compare counted quantity with system snapshots and turn approved variance into auditable correction.

### 4. Review

Define/count/review the physical count and variance through the controlled count lifecycle. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Review](/screens/inventory/inventory-count)

Run physical/cycle counts, compare counted quantity with system snapshots and turn approved variance into auditable correction.

### 5. Approval

Define/count/review the physical count and variance through the controlled count lifecycle. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Approval](/screens/inventory/inventory-count)

Run physical/cycle counts, compare counted quantity with system snapshots and turn approved variance into auditable correction.

### 6. Adjustment

Post the approved variance as an auditable adjustment rather than changing stock totals directly. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Adjustment](/screens/inventory/adjustment)

Post authorized stock adjustments for controlled variances instead of manually editing Stock Levels.

## Important business rules

- Never repair stock by editing totals directly.
- Tracked variance must identify the affected lot or serial where applicable.

## Exceptions and blocked states

- If the next action is missing, verify permissions, status and prerequisites.
- Do not skip a step by forcing a status; use the dedicated workflow action.
- Shortage, partial confirmation, partial payment or unfinished service work keeps the process in follow-up instead of pretending it is complete.
- Corrections after posting use correction/reversal paths instead of deleting history.

## Related screens

- [Inventory](/screens/inventory/)

## Expected result

The workflow is complete when every step reaches its correct terminal state and downstream effects are visible in their owning modules with no open child work blocking completion.
