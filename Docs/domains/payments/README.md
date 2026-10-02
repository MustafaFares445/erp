# Payments Domain

---
status: canonical
owner: payments
last_verified: 2026-10-02
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

These services exist, including Stripe integration abstractions, but the current runtime has no `api/*` routes. `StripeCheckoutService` explicitly documents that no HTTP/webhook adapter exists yet.

Do not describe Stripe checkout as a currently exposed customer endpoint until routes/controllers exist.

## Related Decisions

- [ADR 0008](../../adr/0008-filament-sales-payments-dashboard.md)
- [ADR 0010](../../adr/0010-accounting-receivables-tax-refunds.md)
- [ADR 0012](../../adr/0012-origin-domain-owns-business-facts.md)

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
