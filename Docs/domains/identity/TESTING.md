# Identity & Authorization Testing

---
status: canonical
owner: identity
last_verified: 2026-10-02
verified_against: tests/Feature/Auth plus permission/authorization tests across domains
---

Primary focused auth tests live under `tests/Feature/Auth/`, but authorization is intentionally tested inside each domain as well.

Important regression coverage includes:

- admin/media route access;
- domain permission seeders;
- fixed role matrices;
- action-time authorization;
- cross-module permission leak tests;
- role-assignment audit behavior;
- policy-specific tests.

Any new privileged action requires both policy/service authorization and a regression test proving unrelated roles cannot execute it.
