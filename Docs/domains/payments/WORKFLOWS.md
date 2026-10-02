# Payments Workflows

---
status: canonical
owner: payments
last_verified: 2026-10-02
verified_against: Payments services
---

## Manual Collection

1. Create Draft payment.
2. Attach proof where required.
3. Select invoice allocations.
4. Validate customer/invoice/outstanding rules.
5. Post collection journal.
6. Recognize tax per allocation.
7. Store unallocated remainder as customer deposit.
8. Mark Posted and emit payment-received event.

## Deposit Application

`Posted unallocated payment -> eligible Invoice -> allocation -> debit Customer Deposits / credit AR -> tax recognition`

## Reversal

`Posted -> guard later credits/refunds -> reverse journals/deposit applications/tax -> restore allocations -> Reversed`

## Stripe Checkout Service

`ERP purpose -> validate ownership/server amount -> provider checkout session -> PaymentTransaction Pending`

HTTP/webhook exposure is not currently implemented in the route set.

## Stripe Refund

`Approved ERP Refund -> validate original provider transaction -> idempotent provider refund -> persist provider reference/status -> provider succeeded -> Accounting pays/posts ERP refund`
