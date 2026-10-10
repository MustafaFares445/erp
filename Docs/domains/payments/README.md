# Payments Domain

---
status: canonical
owner: payments
last_verified: 2026-10-07
verified_against: app/Services/Payments, payment enums/policies, tests/Feature/Payments and payment-related Sales tests
---

## Purpose

Payments owns customer collection transactions, invoice allocations, unallocated customer deposits, tax-recognition execution, provider transactions/reconciliation and provider refund execution.

Accounting owns journal truth; Sales owns invoice commercial state.

## Main Code Anchors

- `PaymentService.php`
- `PaymentAllocationService.php`
- `PaymentPostingService.php`
- `TaxRecognitionService.php`
- `CustomerDepositApplicationService.php`
- `StripeCheckoutService.php`
- `StripePaymentReconciliationService.php`
- `StripeRefundService.php`
- `ProviderPaymentSettlementService.php`

## Current HTTP/API Status

These services exist, including Stripe integration abstractions. The rollout-gated Customer Support API exposes a scoped ticket payment-session/status adapter, but there is no general invoice/order Stripe Checkout route and no public Stripe webhook route.

Do not describe the scoped Support payment adapter as a complete customer payment API; broader payment exposure still requires explicit routes/controllers and provider reconciliation entry points.

## Related Decisions

- [ADR 0008](../../adr/0008-filament-sales-payments-dashboard.md)
- [ADR 0010](../../adr/0010-accounting-receivables-tax-refunds.md)
- [ADR 0012](../../adr/0012-origin-domain-owns-business-facts.md)

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
