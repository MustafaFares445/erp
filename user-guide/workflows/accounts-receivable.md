---
title: "Accounts Receivable"
type: workflow
lastVerified: 2026-10-07
---

# Accounts Receivable

Follow customer invoiced value through receivable status, payment allocation, settlement, credit and write-off controls.

<IerpWorkflow :steps='["Issued Invoice", "Accounts Receivable", "Payment", "Allocation", "Settlement / Credit"]' />

## Modules involved

Sales, Accounting.

## When to use this workflow

Use this workflow when you need to complete **Accounts Receivable** end to end while preserving the responsibility boundaries between modules.

## Before you start

- Confirm required master records such as customer, supplier, product or equipment are active.
- Confirm your role has permission for the high-impact actions in each module.
- Review currency, UOM, warehouse and payment-term context when they apply.
- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.

## Step by step

### 1. Issued Invoice

Create and issue the billing document from eligible source context while keeping settlement separate. Verify the commercial source/status, customer and line values. Sales owns the customer document; physical stock still changes only through Inventory.

**Work in:** [Issued Invoice](/screens/sales/invoice)

Create, issue and review customer invoices while keeping document lifecycle, receivable balance and payment settlement as separate controlled facts.

### 2. Accounts Receivable

Review customer open value, allocations and settlement status; correct from the owning source when needed. Verify the posting period, currency, source document and allocation context. Accounting owns the financial truth written by this step.

**Work in:** [Accounts Receivable](/screens/accounting/accounts-receivable)

Review customer receivable balances, invoice exposure and settlement status so outstanding money can be followed to payment, credit or write-off.

### 3. Payment

Record, post and allocate customer money against eligible invoices or deposit context. Verify the commercial source/status, customer and line values. Sales owns the customer document; physical stock still changes only through Inventory.

**Work in:** [Payment](/screens/sales/payment)

Record customer money, allocate it to eligible invoices or deposits, and post/reverse the controlled collection effect.

### 4. Allocation

Record, post and allocate customer money against eligible invoices or deposit context. Verify the commercial source/status, customer and line values. Sales owns the customer document; physical stock still changes only through Inventory.

**Work in:** [Allocation](/screens/sales/payment)

Record customer money, allocate it to eligible invoices or deposits, and post/reverse the controlled collection effect.

### 5. Settlement / Credit

Review customer open value, allocations and settlement status; correct from the owning source when needed. Verify the posting period, currency, source document and allocation context. Accounting owns the financial truth written by this step.

**Work in:** [Settlement / Credit](/screens/accounting/accounts-receivable)

Review customer receivable balances, invoice exposure and settlement status so outstanding money can be followed to payment, credit or write-off.

## Important business rules

- Invoice document status and payment status are separate.
- Unallocated posted money can remain a customer deposit.
- Write-offs require controlled financial treatment.

## Exceptions and blocked states

- If the next action is missing, verify permissions, status and prerequisites.
- Do not skip a step by forcing a status; use the dedicated workflow action.
- Shortage, partial confirmation, partial payment or unfinished service work keeps the process in follow-up instead of pretending it is complete.
- Corrections after posting use correction/reversal paths instead of deleting history.

## Related screens

- [Sales](/screens/sales/)
- [Accounting](/screens/accounting/)

## Expected result

The workflow is complete when every step reaches its correct terminal state and downstream effects are visible in their owning modules with no open child work blocking completion.
