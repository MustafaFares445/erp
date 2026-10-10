---
title: "Accounts Payable"
type: workflow
lastVerified: 2026-10-07
---

# Accounts Payable

Follow supplier billing from received purchasing work into payable liability and supplier payment.

<IerpWorkflow :steps='["Purchase / Receipt", "Supplier Bill", "Accounts Payable", "Supplier Payment", "Allocation"]' />

## Modules involved

Purchasing, Accounting.

## When to use this workflow

Use this workflow when you need to complete **Accounts Payable** end to end while preserving the responsibility boundaries between modules.

## Before you start

- Confirm required master records such as customer, supplier, product or equipment are active.
- Confirm your role has permission for the high-impact actions in each module.
- Review currency, UOM, warehouse and payment-term context when they apply.
- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.

## Step by step

### 1. Purchase / Receipt

Create, approve and send the supplier commitment using supplier-owned commercial data. Verify supplier, purchasing UOM, currency and source demand. Purchasing owns supplier commitment, not physical stock posting.

**Work in:** [Purchase / Receipt](/screens/vendors/purchase-order)

Create, approve and send supplier commitments, then follow confirmation, inbound allocation, physical receipt and billing progress.

### 2. Supplier Bill

Review/approve the supplier bill that creates or updates the payable liability from valid purchasing provenance. Verify the posting period, currency, source document and allocation context. Accounting owns the financial truth written by this step.

**Work in:** [Supplier Bill](/screens/accounting/bill)

Review and approve supplier bills that create Accounts Payable liability from valid purchasing/receiving context.

### 3. Accounts Payable

Review supplier open liability, allocations and settlement status from valid supplier bills. Verify the posting period, currency, source document and allocation context. Accounting owns the financial truth written by this step.

**Work in:** [Accounts Payable](/screens/accounting/accounts-payable)

Review supplier liabilities and payment exposure created from the approved purchasing/billing lifecycle.

### 4. Supplier Payment

Record and allocate payment against supplier liabilities through Accounting. Verify the posting period, currency, source document and allocation context. Accounting owns the financial truth written by this step.

**Work in:** [Supplier Payment](/screens/accounting/supplier-payment)

Record and allocate supplier payments against eligible liabilities while preserving payment and bill provenance.

### 5. Allocation

Record and allocate payment against supplier liabilities through Accounting. Verify the posting period, currency, source document and allocation context. Accounting owns the financial truth written by this step.

**Work in:** [Allocation](/screens/accounting/supplier-payment)

Record and allocate supplier payments against eligible liabilities while preserving payment and bill provenance.

## Important business rules

- Purchasing and receiving provide source context; Accounting owns the liability.
- Payments should preserve allocation provenance to supplier bills.

## Exceptions and blocked states

- If the next action is missing, verify permissions, status and prerequisites.
- Do not skip a step by forcing a status; use the dedicated workflow action.
- Shortage, partial confirmation, partial payment or unfinished service work keeps the process in follow-up instead of pretending it is complete.
- Corrections after posting use correction/reversal paths instead of deleting history.

## Related screens

- [Purchasing](/screens/vendors/)
- [Accounting](/screens/accounting/)

## Expected result

The workflow is complete when every step reaches its correct terminal state and downstream effects are visible in their owning modules with no open child work blocking completion.
