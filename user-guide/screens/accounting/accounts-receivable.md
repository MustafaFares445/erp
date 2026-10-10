---
title: "Accounts Receivable"
module: accounting
pages: ["index"]
screenshots: ["accounting-accounts-receivable-index"]
lastVerified: 2026-10-07
---

# Accounts receivable and customer settlement

<IerpPageSummary purpose="Inspect issued customer invoice balances, aging and settlement without writing ledger history from a report." useWhen="Collecting overdue invoices or investigating partial payments." owner="Accounting" />

## When to use this page

Collecting overdue invoices or investigating partial payments.

## Typical tasks

- Follow the documented “Collections” instructions below.
<IerpScreenshot id="accounting-accounts-receivable-index" title="Customer receivables" description="Outstanding invoices, aging and payment status." imageSrc="/images/en/accounting/accounting-accounts-receivable-index.png" />

Use the list to identify the issued invoice, customer, due date, outstanding amount and credit/write-off adjustments. The AR workspace is a derived read model over posted facts, not a manual accounting correction screen.

## Collections

1. Open the eligible invoice in Sales to confirm its issued status and current outstanding amount.
2. Record a Payment through Payments, specifying the correct customer and active payment method.
3. Allocate all or part of the amount against the same customer's Issued invoices. Allocations must not exceed outstanding balances; unallocated money is a customer deposit.
4. On posting, the payment updates AR and recognizes applicable tax proportionally. The invoice's document status is distinct from its financial Paid/Partially Paid state.
5. Review AR again and use a credit note, refund or independently approved write-off only for the separate business reasons each supports.

Searching, filtering, aging or exporting AR does not post a payment.

## Customer credit balances

A credit note confirmed after an invoice is fully paid creates a customer credit balance, even when outstanding debt is zero. The report includes those customers and displays their credit separately from overdue debt and reconciles **Net receivable = Outstanding − Customer credit balance** with the AR control account. A Draft or Approved refund does not reduce that posted credit. A Paid refund reduces it only by the portion funded from AR, as of the refund journal's entry date; the deposit-funded portion belongs to customer deposits. Customer statements and aging CSV show these credit and refund effects.
