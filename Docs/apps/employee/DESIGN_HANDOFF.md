# Employee App Design Handoff

---
status: canonical
owner: employee-app
last_verified: 2026-10-02
verified_against: design/employee-sales-app-v1.pen and approved employee Pen handoff
---

## Visual Source

`design/employee-sales-app-v1.pen`

## Navigation

The design handoff uses:
- Home
- Tasks
- Visits
- Sales
- Notifications

Profile / Security is accessed through the avatar.

Bottom navigation remains on top-level lists/Home and is hidden during focused flows such as first login, check-in, active visit, AI review, check-out and quotation editing.

## Protected Active Visit

Leaving and returning to the app must restore the active visit rather than silently resetting it.

Back navigation must not discard visit/quotation edits without explicit handling.

## Deep Links

Notifications may deep-link to:
- Task
- Visit
- Visit conversation
- Opportunity
- Quotation
- Performance
- Salary

The implementation must still enforce employee ownership after navigation.

## Design vs Behavior Precedence

- backend code/tests: implemented domain truth;
- canonical Employee behavior docs: approved mobile behavior;
- active API plan: unfinished backend/API work;
- Pen source: visual/layout/component/navigation representation.

The design must not create an action that bypasses a backend lifecycle or permission.

## Developer Checklist Per Screen

Document/implement:
- owning feature/domain;
- backend resource;
- loading/empty/error/offline state;
- transition/action and next screen;
- retry/idempotency behavior;
- media/GPS permissions;
- server-derived values;
- ownership/deep-link guard;
- active-visit resume behavior where applicable.
