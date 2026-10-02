# Accounting Workflows

---
status: canonical
owner: accounting
last_verified: 2026-10-02
verified_against: Accounting services and current accounting lifecycle enums
---

## Journal

`Draft -> validate -> Posted`

Source-backed postings use the same JournalPostingService with source-specific authorization.

Correction:

`Posted Entry -> Reverse -> mirrored Posted reversal in an open period`

## Fiscal Period

`Create/Open -> checklist -> Close`

Mandatory failure either blocks close or requires explicit override permission/reason. Closed periods can be reopened through an audited action.

## Purchase to Pay

`Accepted+Sent PO -> ensure Draft Bill -> Bill approval -> AP/ledger -> Supplier Payment -> allocation/ledger`

PO remains Purchasing-owned; Bill/AP/payment accounting remains Accounting-owned.

## Sales / Collection

`Invoice issue -> Sales posting adapter -> JournalPostingService`

`Payment allocation -> Payments posting/tax adapters -> JournalPostingService`

`Credit Note confirm/reverse -> Sales credit posting adapter -> JournalPostingService`

## Refund

`Draft Refund -> Approve -> Pay -> accounting payout/posting`

For Stripe, provider execution sits between Approved and ERP Paid.

## Receivable Write-Off

`Record Draft -> independent Approve -> write-off posting/tax allocation -> Invoice Written Off`

## Reporting

Posted ledger facts feed Trial Balance, General Ledger, P&L, Balance Sheet, Posting Register, AR/AP and tax register/reconciliation. Reports are not write paths.
