---
title: "Procure to Pay"
type: workflow
lastVerified: 2026-10-07
---

# Procure to Pay

Buy from a supplier through sourcing, Purchase Order commitment, supplier confirmation, Inventory receipt, supplier liability and payment.

<IerpWorkflow :steps='["Purchase Need / RFQ", "Agreement", "Purchase Order", "Supplier Confirmation", "Inbound", "Inventory Receipt", "Supplier Bill / AP", "Supplier Payment"]' />

## Modules involved

Purchasing, Inventory, Accounting.

## When to use this workflow

Use this workflow when you need to complete **Procure to Pay** end to end while preserving the responsibility boundaries between modules.

## Before you start

- Confirm required master records such as customer, supplier, product or equipment are active.
- Confirm your role has permission for the high-impact actions in each module.
- Review currency, UOM, warehouse and payment-term context when they apply.
- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.

## Step by step

### 1. Purchase Need / RFQ

Start from a real external supply need—independent purchasing, replenishment or Sales shortage—and preserve that source when applicable.

**Work in:** [Purchase Need / RFQ](/screens/vendors/purchase-needs)

Review internal demand that requires external purchasing and turn eligible need into RFQ/PO sourcing without losing source provenance.

### 2. Agreement

Use supplier sourcing/agreement context to determine the eligible supplier and commercial terms before committing.

**Work in:** [Agreement](/screens/vendors/purchase-agreement)

Maintain reusable supplier commercial agreements that influence future sourcing and purchasing terms.

### 3. Purchase Order

Create the PO with supplier-owned commercial data, submit/approve as required and send only after authorization.

**Work in:** [Purchase Order](/screens/vendors/purchase-order)

Create, approve and send supplier commitments, then follow confirmation, inbound allocation, physical receipt and billing progress.

### 4. Supplier Confirmation

Record what the supplier actually commits to provide, including partial quantity or promised-date changes.

**Work in:** [Supplier Confirmation](/screens/vendors/supplier-confirmation)

Record supplier-confirmed quantities and promised dates against a sent Purchase Order and expose partial/changed commitment clearly.

### 5. Inbound

Allocate expected inbound quantity to the warehouse destination before the physical receipt is posted.

**Work in:** [Inbound](/screens/inventory/purchase-inbound)

Track expected inbound purchasing work and warehouse allocations before physical receipt is posted by Inventory.

### 6. Inventory Receipt

Create/complete the Inventory receipt. This is the physical stock-posting boundary; Purchasing does not write stock directly.

**Work in:** [Inventory Receipt](/screens/inventory/inventory-operation)

Execute controlled receipts, deliveries and internal transfers while preserving source document, tracking and movement history.

### 7. Supplier Bill / AP

Review/approve the supplier bill and resulting payable liability in Accounting using the valid purchasing/receiving provenance.

**Work in:** [Supplier Bill / AP](/screens/accounting/bill)

Review and approve supplier bills that create Accounts Payable liability from valid purchasing/receiving context.

### 8. Supplier Payment

Pay and allocate the supplier liability through the Accounting supplier-payment workflow.

**Work in:** [Supplier Payment](/screens/accounting/supplier-payment)

Record and allocate supplier payments against eligible liabilities while preserving payment and bill provenance.

## Important business rules

- Purchase Orders own supplier commercial data.
- Supplier Confirmation records committed quantities and promised dates.
- Inventory is the only module that posts physical receipt.
- Supplier bills and payments continue through Accounting and Payments rather than a purchasing-only ledger.

## Exceptions and blocked states

- If the next action is missing, verify permissions, status and prerequisites.
- Do not skip a step by forcing a status; use the dedicated workflow action.
- Shortage, partial confirmation, partial payment or unfinished service work keeps the process in follow-up instead of pretending it is complete.
- Corrections after posting use correction/reversal paths instead of deleting history.

## Related screens

- [Purchasing](/screens/vendors/)
- [Inventory](/screens/inventory/)
- [Accounting](/screens/accounting/)

## Expected result

The workflow is complete when every step reaches its correct terminal state and downstream effects are visible in their owning modules with no open child work blocking completion.
