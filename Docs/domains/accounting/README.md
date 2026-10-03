# Accounting Domain

---
status: canonical
owner: accounting
last_verified: 2026-10-02
verified_against: app/Services/Accounting, accounting enums/policies, tests/Feature/Accounting
---

## Purpose

Accounting owns chart of accounts, fiscal periods, journal posting/reversal, receivables/payables, bills, expenses, supplier payments, refunds, receivable write-offs, tax register/reconciliation and financial reports.

Operational domains may request approved postings, but they do not write ledger rows directly.

## Main Code Anchors

- `JournalPostingService.php`
- `FiscalPeriodService.php`
- `ChartOfAccountService.php`
- `AccountingDocumentService.php`
- `AccountsReceivableService.php`
- `AccountsPayableService.php`
- `FinancialReportService.php`
- `TaxRegisterService.php`
- `PurchaseOrderDraftBillService.php`
- `RefundService.php`
- `ReceivableWriteOffService.php`

## Main UI Surfaces

Chart of Accounts, Journal Entries, Fiscal Periods, Accounts Receivable, Accounts Payable, Bills, Expenses, Supplier Payments, Refunds, Taxes, Receivable Write-Offs and Financial Reports.

The Accounting dashboard follows the [module dashboard layout](../../architecture/SYSTEM_OVERVIEW.md#module-dashboards). It shows posted journal activity next to the tax position for the selected window. Below them, the open period's close checklist (last measured results; viewing never re-runs a check) sits beside the customers with the largest outstanding balance.

## Related Decisions

- [ADR 0007](../../adr/0007-filament-accounting-dashboard.md)
- [ADR 0009](../../adr/0009-accounting-financial-reports.md)
- [ADR 0010](../../adr/0010-accounting-receivables-tax-refunds.md)
- [ADR 0011](../../adr/0011-accounting-payables-expenses-bills.md)
- [ADR 0012](../../adr/0012-origin-domain-owns-business-facts.md)

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
