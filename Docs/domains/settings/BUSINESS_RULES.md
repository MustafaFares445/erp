# Settings Business Rules

---
status: canonical
owner: settings
last_verified: 2026-10-02
verified_against: BusinessConstraints, BusinessConstraintService, ConstraintGuard, CurrencyCatalogService
---

## Business Constraints

Shared constraints are accessed through `BusinessConstraints` and enforced through `ConstraintGuard`/domain callers rather than hard-coded copies.

Current helpers cover limits such as discount/markup/margin, receivable/payable ageing boundaries, overdue reminder days and purchase approval threshold.

Constraint changes and approved overrides use explicit services; an override should be attributed rather than silently ignoring the configured limit.

## Currency Catalog

`CurrencyCatalogService` is the shared authority for currency options/default/normalization.

- active normalization is used where new business records must choose an active currency;
- base normalization supports valid stored/base codes where historical/system compatibility requires it;
- domains should not maintain independent currency selector lists.

## Cache/Freshness

The shared constraint reader exposes an explicit cache-forget path; mutations must invalidate shared constraint state.
