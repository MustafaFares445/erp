---
title: "Fiscal Period Review and Close"
type: workflow
lastVerified: 2026-10-07
---

# Fiscal Period Review and Close

Review posted financial activity and control whether new transactions can affect a fiscal period.

<IerpWorkflow :steps='["Period", "Transaction Review", "Exceptions", "Financial Reports", "Close / Lock"]' />

## Modules involved

Accounting, Reports.

## When to use this workflow

Use this workflow when you need to complete **Fiscal Period Review and Close** end to end while preserving the responsibility boundaries between modules.

## Before you start

- Confirm required master records such as customer, supplier, product or equipment are active.
- Confirm your role has permission for the high-impact actions in each module.
- Review currency, UOM, warehouse and payment-term context when they apply.
- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.

## Step by step

### 1. Period

Review whether the financial period is open for posting and close/lock it only after exceptions are resolved. Verify the posting period, currency, source document and allocation context. Accounting owns the financial truth written by this step.

**Work in:** [Period](/screens/accounting/fiscal-period)

Control fiscal periods, posting availability and close/reopen rules that protect dated financial history.

### 2. Transaction Review

Review the ledger effect produced by the business event; use explicit correcting entries rather than destructive edits. Verify the posting period, currency, source document and allocation context. Accounting owns the financial truth written by this step.

**Work in:** [Transaction Review](/screens/accounting/journal-entry)

Review and post balanced accounting entries, using explicit reversal/correction behavior after posting instead of destructive edits.

### 3. Exceptions

Review the financial output for the selected period and trace discrepancies back to source transactions. Verify the posting period, currency, source document and allocation context. Accounting owns the financial truth written by this step.

**Work in:** [Exceptions](/screens/accounting/financial-report)

Read consolidated financial reports from accounting facts; correct discrepancies in the owning transaction rather than in the report.

### 4. Financial Reports

Review the financial output for the selected period and trace discrepancies back to source transactions. Verify the posting period, currency, source document and allocation context. Accounting owns the financial truth written by this step.

**Work in:** [Financial Reports](/screens/accounting/financial-report)

Read consolidated financial reports from accounting facts; correct discrepancies in the owning transaction rather than in the report.

### 5. Close / Lock

Review whether the financial period is open for posting and close/lock it only after exceptions are resolved. Verify the posting period, currency, source document and allocation context. Accounting owns the financial truth written by this step.

**Work in:** [Close / Lock](/screens/accounting/fiscal-period)

Control fiscal periods, posting availability and close/reopen rules that protect dated financial history.

## Important business rules

- Closing controls future posting; it should not erase historical transactions.
- Corrections may require authorized reopen/reversal behavior according to policy.

## Exceptions and blocked states

- If the next action is missing, verify permissions, status and prerequisites.
- Do not skip a step by forcing a status; use the dedicated workflow action.
- Shortage, partial confirmation, partial payment or unfinished service work keeps the process in follow-up instead of pretending it is complete.
- Corrections after posting use correction/reversal paths instead of deleting history.

## Related screens

- [Accounting](/screens/accounting/)
- [Reports](/screens/reports/)

## Expected result

The workflow is complete when every step reaches its correct terminal state and downstream effects are visible in their owning modules with no open child work blocking completion.
