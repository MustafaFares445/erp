# Reporting & Audit Testing

---
status: canonical
owner: reporting
last_verified: 2026-10-02
verified_against: Accounting/Inventory/Purchasing/Sales/CRM/Employees/Support report tests
---

Reporting tests are distributed with the domains whose facts they report.

Critical guarantees include:

- financial reports are read-only;
- trial balance/general-ledger/report arithmetic reconciles;
- inventory reconciliation/report filters are correct;
- purchasing/sales/support/CRM/employee report authorization is enforced;
- export generation does not mutate source business state.
