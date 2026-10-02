# Sales & Orders Domain

---
status: canonical
owner: sales
last_verified: 2026-10-02
verified_against: app/Services/Sales, Order/Quotation/Invoice/CreditNote enums and policies, tests/Feature/Sales
---

## Purpose

Sales owns the customer commercial lifecycle: quotations, sales orders, delivery/invoice relationships, invoice lifecycle, credit-note correction, procurement demand and sales reporting.

Physical stock remains Inventory-owned; money movement remains Payments-owned; ledger truth remains Accounting-owned.

## Main Code Anchors

- `QuotationService.php`
- `QuotationResponseService.php`
- `QuotationConversionService.php`
- `SalesOrderService.php`
- `OrderWorkflowService.php`
- `OrderCompletionService.php`
- `SalesProcurementRequirementService.php`
- `InvoiceService.php`
- `InvoiceBalanceService.php`
- `InvoicePostingService.php`
- `CreditNoteService.php`
- `CreditNotePostingService.php`

## Main UI Surfaces

Quotations, Customer Quotation Requests, Orders, Delivery Notes, Invoices, Credit Notes, Sales Opportunities, Sales Settings, Payment Terms and Sales Reports.

## Related Decisions

- [ADR 0008](../../adr/0008-filament-sales-payments-dashboard.md)
- [ADR 0010](../../adr/0010-accounting-receivables-tax-refunds.md)
- [ADR 0012](../../adr/0012-origin-domain-owns-business-facts.md)

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
