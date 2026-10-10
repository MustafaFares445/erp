---
title: "Journal Entries"
module: accounting
pages: ["index", "create", "view", "edit"]
screenshots: ["accounting-journal-entry-index", "accounting-journal-entry-create", "accounting-journal-entry-view", "accounting-journal-entry-edit"]
lastVerified: 2026-10-07
---

# Journal entries and ledger corrections

<IerpPageSummary purpose="Prepare, post and reverse balanced general-ledger entries while retaining posted history." useWhen="An authorized accountant needs a manual journal or to investigate posting from a business document." owner="Accounting" />

## When to use this page

An authorized accountant needs a manual journal or to investigate posting from a business document.

## Typical tasks

- Follow the documented “What the operator sees” instructions below.
- Follow the documented “Posting and correction” instructions below.
- Follow the documented “Financial safeguards” instructions below.

## What the operator sees

Use **Journal Entries** to search journal numbers, inspect debit and credit lines, and distinguish Draft from Posted or Reversed history. Every posted journal must have at least two lines, a single debit or credit on each line, equal debit and credit totals, active postable chart accounts, and an open fiscal period for the posting date.

<IerpScreenshot id="accounting-journal-entry-index" title="Journal entry list" description="Find posted and draft entries and the permitted stage actions."  imageSrc="/images/en/accounting/accounting-journal-entry-index.png" />
<IerpScreenshot id="accounting-journal-entry-create" title="Journal entry draft" description="Choose a date and accounts, then review debit and credit amounts." imageSrc="/images/en/accounting/accounting-journal-entry-create.png" />

## Posting and correction

1. In **Create**, select the entry date and add the accounts and monetary lines; saving creates a draft.
2. Open the draft in **View** or **Edit** and reconcile debit and credit totals. Posting is a separate permission-checked confirmation, not an automatic consequence of saving.
3. Before **Post**, confirm the period is open and that the accounts and amounts are correct. Posting changes the ledger and makes the entry immutable.
4. To correct a Posted entry, use the authorized **Reverse** action. Choose a reversal date in an open period; the system posts a new entry with the original debit/credit sides mirrored, preserving the original source and history.

<IerpScreenshot id="accounting-journal-entry-view" title="Review posting or reversal" description="Posted entries expose their source and permission-dependent actions." imageSrc="/images/en/accounting/accounting-journal-entry-view.png" />
<IerpScreenshot id="accounting-journal-entry-edit" title="Edit an unposted entry" description="Posted history is not edited in place." imageSrc="/images/en/accounting/accounting-journal-entry-edit.png" />

## Financial safeguards

Never edit a posted entry's amount or replace the source invoice/payment to 'fix' totals. Invoice issue, payment posting, supplier-bill approval and refund payout invoke authorized Accounting posting services; a manual journal is not a substitute for those origin-domain actions. Missing **Post**, **Edit**, or **Reverse** may indicate either the current status or the user's permissions.

Related: [Fiscal periods](/screens/accounting/fiscal-period), [Chart of accounts](/screens/accounting/chart-of-account), [Financial reports](/screens/accounting/financial-report).
