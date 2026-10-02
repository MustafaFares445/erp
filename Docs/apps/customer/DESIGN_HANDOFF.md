# Customer App Design Handoff

---
status: canonical
owner: customer-app
last_verified: 2026-10-02
verified_against: design/customer-app-v1.pen and adopted Customer V1 product decisions
---

## Visual Source

`design/customer-app-v1.pen`

This is the editable visual source for Customer App V1.

## Precedence

When implementation questions arise:

1. current backend code/tests define already-implemented domain behavior;
2. this app behavior documentation defines approved customer-facing product behavior;
3. active implementation plans define unfinished backend/API work;
4. the Pen file defines layout, visual hierarchy, component usage and screen-to-screen presentation.

A visual screen must not override ownership/security/business rules.

## Developer Handoff Rules

For every interactive screen, implementation should identify:

- screen/route name;
- owning domain;
- record ownership rule;
- loading/empty/error/restricted state;
- allowed actions and target screen;
- server-sourced status/price/amount;
- media requirements;
- deep-link target where relevant.

## Restricted User States

Guest and Under Review screens must visually hide/disable commercial actions and customer price without implying backend authorization is optional. The API must enforce the same restriction.

## Payment UI

Pay Now must be rendered only from server-provided eligibility/amount context.

Provider pending/failed/requires-action/succeeded UI is presentation state; ERP posting follows verified server/provider settlement.

## Design Changes

If a future Pen edit changes navigation or layout only, behavior docs do not need rewriting.

If it changes an action, eligibility rule, ownership rule, lifecycle or backend dependency, update these canonical docs in the same change.
