# Payments Testing

---
status: canonical
owner: payments
last_verified: 2026-10-02
verified_against: tests/Feature/Payments and Sales/Accounting integration tests
---

## Primary Test Location

`tests/Feature/Payments/`

Focused tests include:

- `CustomerDepositApplicationServiceTest`
- `PaymentReversalGuardTest`
- `ProviderPaymentSettlementServiceTest`
- `StripeCheckoutServiceTest`
- `StripePaymentReconciliationServiceTest`
- `StripeRefundServiceTest`
- `SystemActorResolverTest`

Payment posting/allocation/tax behavior is also exercised by Sales and Accounting integration tests, including invoice deposit application, cumulative tax rounding, credit-note tax behavior and refund posting.

Changes to allocation or tax recognition require cross-domain regression coverage, not only a Payments-local test.
