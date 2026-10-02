# Payments Business Rules

---
status: canonical
owner: payments
last_verified: 2026-10-02
verified_against: PaymentService, allocation/posting/tax/deposit/Stripe services
---

## Manual Payment Lifecycle

Payment status is `Draft -> Posted -> Reversed`.

Draft requires an active payment method and positive amount. Currency is normalized through the shared catalog.

Posting requires an active payment method, required proof, and allocations that do not exceed the payment.

## Allocation

An allocation:

- is positive;
- targets an Issued invoice;
- belongs to the same customer;
- cannot exceed outstanding balance;
- occurs at most once per payment/invoice pair.

Allocation updates invoice paid amount and synchronizes invoice/order financial status.

## Accounting Posting

On posting:

- debit the payment method collection account for the full amount;
- credit Accounts Receivable for allocated amount;
- credit Customer Deposits for unallocated remainder.

A payment must settle receivable, create a deposit, or both.

## Tax Recognition

Tax is recognized per invoice allocation, not merely because an invoice exists.

Recognition is proportional to the effective remaining tax claim after confirmed credits. The settling allocation absorbs rounding residue.

## Customer Deposits

Unallocated posted customer money is a deposit. Applying it to an invoice creates an allocation, transfers Accounting balance from customer deposits to receivable, and runs the same tax-recognition logic.

## Reversal

Reversal is blocked when later confirmed credits/refunds depend on the payment.

A valid reversal reverses journals, deposit applications, tax recognition and allocations before marking the payment Reversed.

## Stripe Capability

Stripe service-level checkout exists for invoice outstanding balance, order prepayment/deposit capped by order total, and pending ticket payment link amount.

Server-known amounts are authoritative.

An ERP Refund remains the business authority. Stripe execution is idempotent and Accounting marks it Paid only after provider success.

## Current Exposure

Provider services do not imply an exposed API. There are currently no runtime `api/*` routes.
