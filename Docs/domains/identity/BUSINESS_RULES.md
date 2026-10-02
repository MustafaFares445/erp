# Identity & Authorization Business Rules

---
status: canonical
owner: identity
last_verified: 2026-10-02
verified_against: Identity service, policies, permission enums and cross-module authorization tests
---

- Authorization is enforced by policies/Gates/services, not only by hidden UI actions.
- Domain permissions remain scoped: Inventory, Purchasing, Sales, Accounting, CRM, Employees and Support each expose distinct permission namespaces.
- Dashboard role assignment is a domain service operation and is audited.
- Fixed dashboard roles/permission seed data must remain synchronized with policy expectations.
- A user having dashboard/admin access does not automatically grant every scoped business permission unless the configured System Admin behavior explicitly allows it.
- Cross-module permission-leak tests are part of the security boundary.
