---
title: "Accounting"
module: accounting
lastVerified: 2026-10-07
---

# Accounting

Accounting owns the financial truth of IERP: ledgers, receivables, payables, tax, periods, supplier liabilities and posted financial corrections.

## How to use this module

Start from the module dashboard to understand queues and exceptions, then open the screen that owns the record or action you need. The structure below follows the dashboard module switcher and sidebar.

## Dashboard screens

<div class="module-grid">
<a class="module-card" href="/guide/screens/accounting/dashboard"><strong>Accounting Dashboard</strong><span>Use the Accounting Dashboard to review ledger, receivable, payable and period-level financial signals before opening detailed accounting work.</span></a>
<a class="module-card" href="/guide/screens/accounting/chart-of-account"><strong>Chart of Accounts</strong><span>Maintain the chart of accounts used by posting rules and financial reporting while preserving stable historical account references.</span></a>
<a class="module-card" href="/guide/screens/accounting/journal-entry"><strong>Journal Entries</strong><span>Review and post balanced accounting entries, using explicit reversal/correction behavior after posting instead of destructive edits.</span></a>
<a class="module-card" href="/guide/screens/accounting/bank-statement"><strong>Bank Reconciliation</strong><span>Reconcile bank-statement activity with ERP financial records and investigate unmatched or inconsistent movements.</span></a>
<a class="module-card" href="/guide/screens/accounting/fiscal-period"><strong>Fiscal Periods</strong><span>Control fiscal periods, posting availability and close/reopen rules that protect dated financial history.</span></a>
<a class="module-card" href="/guide/screens/accounting/accounts-receivable"><strong>Accounts Receivable</strong><span>Review customer receivable balances, invoice exposure and settlement status so outstanding money can be followed to payment, credit or write-off.</span></a>
<a class="module-card" href="/guide/screens/accounting/accounts-payable"><strong>Accounts Payable</strong><span>Review supplier liabilities and payment exposure created from the approved purchasing/billing lifecycle.</span></a>
<a class="module-card" href="/guide/screens/accounting/bill"><strong>Bills</strong><span>Review and approve supplier bills that create Accounts Payable liability from valid purchasing/receiving context.</span></a>
<a class="module-card" href="/guide/screens/accounting/supplier-payment"><strong>Supplier Payments</strong><span>Record and allocate supplier payments against eligible liabilities while preserving payment and bill provenance.</span></a>
<a class="module-card" href="/guide/screens/accounting/expense"><strong>Expenses</strong><span>Record business expenses and move them through approval/payment states with the correct accounting treatment.</span></a>
<a class="module-card" href="/guide/screens/accounting/refund"><strong>Refunds</strong><span>Approve and pay customer refunds from eligible credit/deposit sources while keeping cash movement separate from physical returns.</span></a>
<a class="module-card" href="/guide/screens/accounting/tax"><strong>Taxes</strong><span>Maintain tax definitions and review tax-related accounting configuration used by commercial documents and reports.</span></a>
<a class="module-card" href="/guide/screens/accounting/financial-report"><strong>Financial Reports</strong><span>Read consolidated financial reports from accounting facts; correct discrepancies in the owning transaction rather than in the report.</span></a>
<a class="module-card" href="/guide/screens/accounting/currency"><strong>Accounting Setup</strong><span>Maintain the shared currency catalog, including active/default currency choices used by new transactions while preserving historical codes.</span></a>
<a class="module-card" href="/guide/screens/accounting/payment-term"><strong>Payment Terms</strong><span>Define reusable payment terms that drive due-date expectations for customer and supplier commercial documents.</span></a>
<a class="module-card" href="/guide/screens/accounting/payment-method"><strong>Payment Methods</strong><span>Maintain allowed payment methods used when recording customer or supplier money movement.</span></a>
<a class="module-card" href="/guide/screens/accounting/sales-setting"><strong>Tax & Posting Accounts</strong><span>Configure tax/posting account behavior shared by commercial workflows without rewriting already-posted history.</span></a>
<a class="module-card" href="/guide/screens/accounting/receivable-write-off"><strong>Receivable Write Off</strong><span>Perform a controlled receivable write-off when authorized collection policy requires reducing an outstanding customer balance.</span></a>
</div>

## Related workflows

- [Accounts Receivable](/workflows/accounts-receivable)
- [Accounts Payable](/workflows/accounts-payable)
- [Fiscal Period Review and Close](/workflows/period-close)
- [Customer Return to Refund](/workflows/customer-return-to-refund)

## Ownership rule

Posted accounting actions affect ledger truth. Historical posted entries should be corrected by reversal, credit or compensating entries instead of destructive edits.
