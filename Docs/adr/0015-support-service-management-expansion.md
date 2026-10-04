# ADR 0015: Support Service-Management Expansion

**Status**: Accepted

**Date**: 2026-10-04

**Deciders**: Project Owner

**Related**: `config/support.php`, `app/Services/Support`, `app/Filament/Resources/Tickets/Pages/ViewTicket.php`, `app/Filament/Resources/SupportEquipment`, `routes/api.php`, `tests/Feature/Support`, `tests/Feature/Api/Customer`

## Context

The existing Support & Maintenance domain already owned ticket intake/triage, maintenance requests, service records, preventive schedules, warranty decisions, parts usage and billing handoffs. The missing operational layer was service-management UX and orchestration: one agent workspace, milestone-level SLA tracking, team/queue routing, safe automation, technician dispatch, equipment-centric history, a customer support channel, knowledge reuse, and service-quality metrics.

Replacing the existing domain would have duplicated warranty, inventory, sales, payments and accounting behavior. The expansion therefore keeps the current Ticket → Maintenance Request → Service Record chain and adds operational capabilities around it.

## Decision

### Case workspace

The Ticket view is the canonical support-agent workspace. Public replies and internal notes share one chronological stream, while context, SLA state, equipment/warranty, maintenance work and suggested knowledge remain visible without leaving the case.

Internal notes are never exposed through customer-facing resources.

### SLA v2

SLA policies may resolve by priority, ticket type, service path, service level, customer, product and support team.

- Business-time calendars and exceptions define working time.
- First-response and resolution milestones are snapshotted per ticket.
- `tickets.response_due_at`, `resolution_due_at` and breach flags remain compatibility projections used by existing UI/reporting.
- Waiting-for-customer may pause resolution milestones according to policy.
- Reconciliation is scheduled and remains idempotent.

### Teams, skills, queues and routing

Support teams, membership capacity, skills, queue definitions and deterministic routing rules are first-class data.

Smart routing is rollout-gated. When enabled, rule precedence, required skills, capacity and least-loaded assignment drive routing. Manual assignment remains available and auditable.

### Safe automation

Support automation is declarative: fixed event keys, structured conditions and an allow-list of actions. Rules never execute arbitrary code. Runs are recorded with event identifiers to avoid duplicate execution.

Automation is rollout-gated and stale waiting-customer checks are scheduler-driven.

### Field service and Equipment 360

On-site service stays under the existing Maintenance Request / Service Record model.

- `ServiceAppointment` adds dispatch, en-route, check-in/check-out, coordinates, evidence and customer sign-off.
- Technician overlap is rejected.
- Equipment 360 is a read-only service-history projection around `SerializedInventoryUnit`; Inventory remains the owner of custody and stock truth.

### Customer support API

Customer support routes use Sanctum and authenticated ownership.

- Customer identity is derived from the authenticated user; arbitrary `customer_id` is never trusted.
- Ticket, equipment, maintenance, conversation, attachments, diagnostic-payment session/status and CSAT access are ownership-scoped.
- Internal notes and internal accounting/cost data are excluded.
- Stripe checkout amounts are derived from the server-side ticket payment link.
- Private media downloads require ticket ownership.
- The complete customer Support API is rollout-gated.

### Knowledge base

Knowledge articles have draft/published/archived states and internal/customer/both visibility.

Articles may target products and ticket types for deterministic suggestions. Agents can share only published customer-visible articles and can mark articles used in resolution. Customer search/suggestions expose only published customer-visible content.

Knowledge is rollout-gated.

### CSAT and reporting

A closed ticket may receive at most one customer satisfaction response.

Support reporting adds backlog aging, first-response and resolution timing, milestone compliance, reopen rate, CSAT, assignment load, technician time load, repeat-failure signals and warranty-recovery performance. Technician reporting does not invent a utilization percentage until an authoritative capacity calendar exists.

### Notifications

Existing notification infrastructure remains canonical. Ticket updates, ticket-close feedback requests, SLA-at-risk alerts and maintenance billing use `NotificationDispatcher` and seeded templates. SLA alerts prefer the assigned agent/team manager, with permission-based fallback.

### Business-facing navigation and rollout

Feature switches live in `config/support.php`, but feature availability does not imply a separate sidebar destination.

The normal Support navigation is intentionally limited to the project workflow: Dashboard, Tickets, Maintenance Requests, Service Records, Maintenance Schedules, Field Service, Customer Equipment/Equipment 360 and one Service Policies workspace. SLA calendars, service levels, entitlements and warranty policies live behind that single configuration destination.

Teams, skills, queues, routing rules, automation rules and Knowledge Base remain implemented extensions. When any of those capabilities is enabled, their administration is grouped behind one **Advanced Support** workspace rather than expanding the normal navigation.

- Case Workspace, SLA v2 and Field Service default on.
- Smart routing, automation, knowledge base, customer Support API and CSAT default off.
- Filament resources and HTTP endpoints are blocked, not merely hidden, while their switches are off.
- Migrations are additive; compatibility columns remain until a later explicit deprecation decision.

## Consequences

- Support grows without taking ownership away from Inventory, Sales, Payments or Accounting.
- Existing ticket/maintenance flows remain valid while operational tooling can be enabled incrementally.
- Mobile/customer work can consume a narrow ownership-safe API without exposing the admin domain model.
- Feature switches provide a reversible rollout path, but migrations and seeded permissions still deploy ahead of activation.
- Reports now use explicit operational facts; metrics that require unavailable capacity/business truth are not fabricated.
