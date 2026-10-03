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

The Sales dashboard follows the [module dashboard layout](../../architecture/SYSTEM_OVERVIEW.md#module-dashboards). It has salesperson and customer filters. Top customers and salesperson performance are paginated tables. When no quotation carries a salesperson, top customers takes the full row.

The Invoices list uses the standard list-table experience ([ADR 0014](../../adr/0014-standard-list-table-experience.md)): a view tab bar (status presets, Starred, saved views), per-user favorites, Group by (status, customer, invoice date, due date) and slide-over rule filters. Trashed stays a separate filter.

## Related Decisions

- [ADR 0008](../../adr/0008-filament-sales-payments-dashboard.md)
- [ADR 0010](../../adr/0010-accounting-receivables-tax-refunds.md)
- [ADR 0012](../../adr/0012-origin-domain-owns-business-facts.md)

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
