# Sales Testing

---
status: canonical
owner: sales
last_verified: 2026-10-02
verified_against: tests/Feature/Sales
---

## Test Location

`tests/Feature/Sales/`

## Critical Coverage

Tests cover:

- quotation lifecycle, decisions, pricing/floor overrides, expiry/requote and no-stock-effect;
- quotation-to-order conversion/aggregation;
- direct order draft/confirm/release/cancel/close;
- procurement and cross-module flow;
- delivery-note and consolidated invoicing;
- invoice issuing, documents, delivery linkage, cumulative rounding, deposits and receipt confirmation;
- credit-note lifecycle/tax effects;
- payment terms/settings;
- permissions/navigation/resources/reports/dashboard metrics.

Representative tests: `QuotationLifecycleTest`, `QuotationTouchesNoStockTest`, `QuotationConversionTest`, `CrossModuleEndToEndTest`, `OrderDraftInvoiceCompletionTest`, `InvoiceDeliveryReleaseTest`, `ConsolidatedInvoicingTest`, and `CreditNoteLifecycleTest`.
