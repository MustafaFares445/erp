# Reporting & Audit Business Rules

---
status: canonical
owner: reporting
last_verified: 2026-10-02
verified_against: domain report services and financial-report read-only tests
---

- Reports read persisted domain facts and must preserve the semantics of the owning domain.
- Financial reports are read-only and expose discrepancies rather than repairing ledger data.
- Filters/exports must not alter business records.
- Audit/activity history is evidence of changes, not an alternate state machine.
- Domain-specific authorization remains required for sensitive reports.
- Cross-domain supplier comparison/reporting may aggregate data but must not write supplier, pricing, purchasing or inventory state.
