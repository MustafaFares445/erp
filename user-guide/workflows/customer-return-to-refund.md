---
title: "Customer Return to Refund"
type: workflow
lastVerified: 2026-10-07
---

# Customer Return to Refund

Handle the physical return and its financial consequence without mixing Inventory and Accounting responsibilities.

<IerpWorkflow :steps='["Return Request", "Customer Return", "Inspection", "Disposition", "Inventory Posting", "Credit Note", "Refund"]' />

## Modules involved

CRM, Inventory, Sales, Accounting.

## When to use this workflow

Use this workflow when you need to complete **Customer Return to Refund** end to end while preserving the responsibility boundaries between modules.

## Before you start

- Confirm required master records such as customer, supplier, product or equipment are active.
- Confirm your role has permission for the high-impact actions in each module.
- Review currency, UOM, warehouse and payment-term context when they apply.
- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.

## Step by step

### 1. Return Request

Capture the customer's return request and review whether it can be accepted into the operational return flow. Verify customer/opportunity context and approval state. CRM provides relationship context without bypassing Sales, Inventory or Accounting ownership.

**Work in:** [Return Request](/screens/crm/customer-return-request)

Review customer return requests against completed delivery provenance and convert approved requests into Draft Inventory Returns.

### 2. Customer Return

Receive/inspect the returned quantity and determine the physical disposition through Inventory. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Customer Return](/screens/inventory/return)

Inspect and post physical customer returns, their tracking provenance and disposition before separate financial credit/refund work.

### 3. Inspection

Receive/inspect the returned quantity and determine the physical disposition through Inventory. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Inspection](/screens/inventory/return)

Inspect and post physical customer returns, their tracking provenance and disposition before separate financial credit/refund work.

### 4. Disposition

Record the approved condition/disposition change so the physical item state remains auditable. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Disposition](/screens/inventory/inventory-condition-change)

Move stock between saleable, quarantine, damaged or other supported conditions with an auditable reason and tracking context.

### 5. Inventory Posting

Receive/inspect the returned quantity and determine the physical disposition through Inventory. Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.

**Work in:** [Inventory Posting](/screens/inventory/return)

Inspect and post physical customer returns, their tracking provenance and disposition before separate financial credit/refund work.

### 6. Credit Note

Create the financial credit against eligible invoiced/returned value without changing physical stock directly. Verify the commercial source/status, customer and line values. Sales owns the customer document; physical stock still changes only through Inventory.

**Work in:** [Credit Note](/screens/sales/credit-note)

Create controlled customer credits against invoiced value and preserve the reason, source invoice and any return provenance.

### 7. Refund

Execute the approved money-return path after the required financial/return prerequisites are satisfied. Verify the posting period, currency, source document and allocation context. Accounting owns the financial truth written by this step.

**Work in:** [Refund](/screens/accounting/refund)

Approve and pay customer refunds from eligible credit/deposit sources while keeping cash movement separate from physical returns.

## Important business rules

- Returned quantity must preserve original delivery, lot, serial and movement provenance where tracked.
- Inventory decides Saleable, Quarantine, Damaged or Supplier Return disposition.
- A Credit Note is a financial correction and cannot credit more than the returned quantity still available for credit.
- Refunds do not directly mutate physical stock.

## Exceptions and blocked states

- If the next action is missing, verify permissions, status and prerequisites.
- Do not skip a step by forcing a status; use the dedicated workflow action.
- Shortage, partial confirmation, partial payment or unfinished service work keeps the process in follow-up instead of pretending it is complete.
- Corrections after posting use correction/reversal paths instead of deleting history.

## Related screens

- [CRM](/screens/crm/)
- [Inventory](/screens/inventory/)
- [Sales](/screens/sales/)
- [Accounting](/screens/accounting/)

## Expected result

The workflow is complete when every step reaches its correct terminal state and downstream effects are visible in their owning modules with no open child work blocking completion.
