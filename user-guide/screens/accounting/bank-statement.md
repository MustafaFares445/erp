---
title: "Bank Reconciliation"
module: accounting
pages: ["index", "create", "view"]
screenshots: ["accounting-bank-statement-index", "accounting-bank-statement-create", "accounting-bank-statement-view"]
lastVerified: 2026-10-07
---

# Bank statements and reconciliation

<IerpPageSummary purpose="Import bank statement movements and reconcile them with authorized ledger/payment facts." useWhen="Reconciling a bank account statement for a specific date range." owner="Accounting" />

## When to use this page

Reconciling a bank account statement for a specific date range.

## Typical tasks

- Follow the documented “Preparation and import” instructions below.
- Follow the documented “Reconcile without inventing ledger entries” instructions below.

## Preparation and import

Choose an **active payment method** linked to an active, postable bank chart account; select currency and dates. Enter opening and closing balances and at least one signed transaction row (positive for a deposit; negative for a withdrawal). Import rejects an inconsistent opening balance plus movements versus the closing balance.

<IerpScreenshot id="accounting-bank-statement-index" title="Bank reconciliation queue" description="Open statements, progress and status filters." imageSrc="/images/en/accounting/accounting-bank-statement-index.png" />
<IerpScreenshot id="accounting-bank-statement-create" title="Statement import form" description="Identify account, period, balances and signed movements." imageSrc="/images/en/accounting/accounting-bank-statement-create.png" />
<IerpScreenshot id="accounting-bank-statement-view" title="Statement reconciliation detail" description="Inspect imported lines, matches and remaining differences." imageSrc="/images/en/accounting/accounting-bank-statement-view.png" />

## Reconcile without inventing ledger entries

1. Open the imported statement and inspect transaction lines and proposed matches. A suggestion is not a posting.
2. Match against the actual eligible payment or posted ledger entry, checking sign, amount, date and reference.
3. Resolve any remaining difference through the authorized difference workflow with an explanation; never force a mismatch into an unrelated payment.
4. **Close reconciliation** is only available while Open, when every line is fully reconciled. An empty statement cannot be closed.

**Screenshot prerequisite:** View needs an actual imported statement. Absence of one in the demo database is not a defect; do not manufacture bank history for screenshots.
