---
status: active-plan
owner: support
last_verified: 2026-10-04
---

# Support & Maintenance Service-Management Implementation Plan

## Goal

Expand the existing Support & Maintenance domain into a stronger service-management workflow without replacing its canonical business boundary:

`Ticket → Maintenance Request → Service Record`

Inventory continues to own stock/custody, Sales owns quotations/invoices, Payments owns provider/payment behavior, and Accounting owns ledger truth.

## Scope decision

The implementation originally explored a broader ITSM shape. The final project-facing scope is intentionally narrower.

### Core IERP Support/Maintenance

These capabilities are part of the normal product experience and should be visible in the primary Support navigation:

- Ticket Case Workspace, payment/blocking state and assignment.
- Maintenance Requests and Service Records.
- Preventive Maintenance Schedules.
- Field Service appointments for on-site work.
- Equipment 360 as a read-only Support projection of serialized Inventory truth.
- SLA / Service Level / Entitlement / Warranty configuration grouped as one **Service Policies** workspace.
- Customer Support API where required by the customer application.
- Operational Support reporting and notifications.

### Optional advanced capability

Teams/Skills/Queues/Smart Routing, declarative Automation and Knowledge Base remain implemented but rollout-gated. They must not appear as separate normal sidebar modules. When enabled, they are grouped under one **Advanced Support** workspace.

This preserves the implementation investment without turning IERP into a generic ITSM product or making administrators configure concepts that the documented business process does not require.

## Rollout phases

- [x] **Phase 0 — Governance and architecture**
- [x] **Phase 1 — Case Workspace**
- [x] **Phase 2 — SLA v2, service levels and entitlements**
- [x] **Phase 3 — Optional smart routing foundation**
- [x] **Phase 4 — Optional safe automation foundation**
- [x] **Phase 5 — Field service**
- [x] **Phase 6 — Equipment 360**
- [x] **Phase 7 — Customer Support API**
- [x] **Phase 8 — Optional Knowledge Base**
- [x] **Phase 9 — CSAT and service-management reporting**
- [x] **Phase 10 — Notification wiring**
- [ ] **Phase 11 — Hardening and release validation**

The core navigation has been reduced to eight business destinations. Optional advanced resources stay behind rollout flags and a single advanced workspace.

## Phase 11 checklist

- [x] Filament resources are blocked as well as hidden when staged features are disabled.
- [x] Customer API and knowledge/CSAT endpoints are feature-gated and ownership-scoped.
- [x] Composite operational indexes cover Support queue/dashboard/API/report query patterns.
- [x] Canonical configuration, API, commands, permissions, status and Support-domain docs are updated.
- [x] ADR 0015 records the architectural boundary and rollout decision.
- [ ] `composer test:lint`
- [ ] `composer test:docs`
- [ ] `composer test:types`
- [ ] `composer test:type-coverage`
- [ ] `composer test:coverage`
- [ ] Final clean working-tree review and release/commit grouping.

## Rollout order

Production activation should be incremental:

1. Deploy additive migrations, seed permissions/templates/service levels and leave risky switches off.
2. Keep Case Workspace and SLA v2 enabled.
3. Enable Smart Routing after team/skill/rule data is configured.
4. Enable Automation after rules are reviewed.
5. Enable Field Service after technician dispatch data and mobile/ops process are ready.
6. Enable Knowledge Base after customer-visible content is reviewed.
7. Enable Customer Support API only when the customer application is ready for the implemented contract.
8. Enable CSAT with the customer support channel so post-close feedback has a usable destination.

## Rollback principle

Feature switches provide behavioral rollback. Data migrations are additive and are not automatically reversed during an application rollback. Do not drop SLA snapshots, routing history, automation runs, appointments, knowledge links or CSAT records merely because a feature switch is turned off.
