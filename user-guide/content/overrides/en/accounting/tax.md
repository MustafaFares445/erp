# Taxes and recognition register

<IerpPageSummary purpose="Inspect posted input tax, deferred output tax and tax recognized on actual customer collections." useWhen="Reconciling invoice tax, partial collections and credits." owner="Accounting and Payments" />

## When to use this page

Reconciling invoice tax, partial collections and credits.

## Typical tasks

- Follow the documented “What affects tax” instructions below.
- Follow the documented “Operator checks” instructions below.

<IerpScreenshot id="accounting-tax-index" title="Tax recognition entries" description="Filter tax direction and inspect amount, source and date." imageSrc="/images/en/accounting/accounting-tax-index.png" />
<IerpScreenshot id="accounting-tax-register" title="Tax register" description="Compare recognized and deferred tax across financial periods." imageSrc="/images/en/accounting/accounting-tax-register.png" />

## What affects tax

Issuing an invoice creates the authorized receivable/revenue and deferred-tax accounting; it does **not** recognize all output tax as paid. A posted customer payment with an allocation to an Issued invoice recognizes tax proportionally to the invoice's effective tax claim. Unallocated customer money remains a deposit and recognizes invoice tax when applied to an eligible invoice. A settling allocation absorbs rounding residue.

A Confirmed credit note adjusts the remaining collectible tax claim. Payment reversal unwinds its tax effect unless later dependent credits or refunds prevent reversal.

## Operator checks

1. Open the tax list or register and filter by direction, date and document.
2. Reconcile each row to its source invoice, allocation, credit or reversal.
3. When a partial payment appears, do not expect the entire invoice's tax to be recognized.
4. Investigate an unexplained amount through the source financial workflow, not by editing the tax register.

Related: [Receivables](/screens/accounting/accounts-receivable), [Journal entries](/screens/accounting/journal-entry).
