# Accounting Testing

---
status: canonical
owner: accounting
last_verified: 2026-10-02
verified_against: tests/Feature/Accounting
---

## Test Location

`tests/Feature/Accounting/`

## Critical Coverage

The suite covers:

- chart-of-account tree/management;
- fiscal period overlap/close/reopen/checklist;
- journal validation/posting/reversal/immutability;
- source posting architecture;
- accounts receivable;
- payables lifecycle and supplier references;
- expenses/bills/supplier payments;
- refunds/deposit posting;
- receivable write-offs/maker-checker;
- tax register/reconciliation;
- financial reports/exports/read-only guarantees;
- permissions/navigation/schema/relations.

Representative tests: `JournalPostingServiceTest`, `JournalReversalTest`, `PostedEntryImmutabilityTest`, `FiscalPeriodServiceTest`, `PeriodCloseChecklistTest`, `AccountsReceivableServiceTest`, `PayablesLifecycleTest`, `RefundDepositPostingTest`, `ReceivableWriteOffTest`, `FinancialReportReadOnlyTest`, and `NoAutomaticPostingTest`.

Any new source posting path must add explicit accounting regression coverage and must not bypass `JournalPostingService`.
