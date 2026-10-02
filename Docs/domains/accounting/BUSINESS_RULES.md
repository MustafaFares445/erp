# Accounting Business Rules

---
status: canonical
owner: accounting
last_verified: 2026-10-02
verified_against: JournalPostingService, FiscalPeriodService, AccountingDocumentService, refund/write-off/report services and tests
---

## Ledger Write Path

`JournalPostingService` is the central general-ledger write interface. Domain posting adapters use it instead of writing journal rows directly.

## Journal Draft vs Posted

A Draft may be incomplete or unbalanced.

Posting requires:

- at least two lines;
- exactly one non-zero debit/credit side per line;
- no negative values;
- active, postable accounts;
- exact debit/credit equality in minor units;
- an open fiscal period containing the entry date.

Posted entries are immutable. Correction uses a reversing entry. A reversal mirrors debit/credit and must itself post into an open period.

## Fiscal Periods

Fiscal periods cannot overlap.

Close runs a persisted checklist. Mandatory failures block close unless an actor with close-override permission supplies a written reason; override is explicitly audited.

Periods with posted entries are not deletable by the ordinary delete path. Closed periods can be reopened through the authorized/audited workflow.

## AR / AP

AR/AP services are derived/read models over posted operational/accounting facts. They provide summaries, aging, details and reconciliation, not an alternate ledger write path.

## Bills / Expenses / Supplier Payments

Accounting owns these documents and their lifecycle/posting behavior.

Purchase-order first-send can provision a Draft Bill through `PurchaseOrderDraftBillService`, but Purchasing does not post the ledger.

`AccountingDocumentService::issueInvoice()` is a compatibility facade and delegates invoice lifecycle ownership back to Sales.

## Refunds

ERP Refund is the business authority. Accounting validates available credit/deposit/credit-note sources and owns approve/pay/cancel accounting behavior.

Stripe may execute an approved refund with the provider, then calls Accounting for ERP payout/posting.

## Receivable Write-Off

Recording requires an issued invoice, matching customer, positive amount not above outstanding, and a reason.

Approval is maker-checker separated, posts write-off/tax effect, and moves the invoice to Written Off.

## Reports

Trial Balance, General Ledger, P&L, Balance Sheet and Posting Register are read/report paths. Reporting must not silently repair ledger discrepancies.
