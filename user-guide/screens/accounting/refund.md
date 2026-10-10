---
title: "Refunds"
module: accounting
pages: ["index"]
screenshots: ["accounting-refund-index"]
lastVerified: 2026-10-07
---

# Customer refunds and payout

<IerpPageSummary purpose="Approve and pay eligible customer refunds with source traceability and authorized accounting effects." useWhen="A confirmed credit note or remaining customer deposit must be returned to the customer." owner="Accounting" />

## When to use this page

A confirmed credit note or remaining customer deposit must be returned to the customer.

## Typical tasks

- Follow the documented “Before approving a refund” instructions below.
- Follow the documented “Correct payout flow” instructions below.

<IerpScreenshot id="accounting-refund-index" title="Refund requests and states" description="Review Draft, Approved and Paid refunds and their source." imageSrc="/images/en/accounting/accounting-refund-index.png" />

## Before approving a refund

Confirm the customer, credit source, refund amount, active method and required reason. A credit-note source must be confirmed and not reversed; without a credit note, the refund may use eligible unapplied customer deposit. Available refund amounts cannot exceed the remaining approved credit or deposit.

## Correct payout flow

1. Record the Draft refund with source, amount and reason. The draft does not pay the customer.
2. Use **Approve** after verifying eligibility. This authorizes the business refund but does not necessarily execute an external provider payment.
3. For a non-Stripe method, **Mark refund paid** is available when Approved with required permission. For Stripe, provider execution must succeed before the ERP refund can settle.
4. Verify the resulting refund status, payment reference and accounting postings. **Cancel draft refund** applies only to an eligible Draft.

Financial credit, inventory return and actual cash refund are separate auditable operations. Refunding money does not itself return stock.
