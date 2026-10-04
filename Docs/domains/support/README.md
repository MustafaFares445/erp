# Support & Maintenance Domain

---
status: canonical
owner: support
last_verified: 2026-10-04
verified_against: app/Services/Support, support enums/permissions, routes/api.php, tests/Feature/Support, tests/Feature/Api/Customer
---

## Purpose

Support owns ticket intake/triage/lifecycle, SLA policy and milestone execution, customer support conversation, maintenance requests and service records, preventive schedules, warranty decisions/recovery, service cost/billing coordination, field-service dispatch and service-quality reporting.

The domain keeps the canonical execution chain:

`Ticket → Maintenance Request → Service Record`

The service-management expansion is recorded in [ADR 0015](../../adr/0015-support-service-management-expansion.md).

## Project Scope and Main UI Surfaces

IERP is not intended to expose every service-management capability as a separate day-to-day module destination. The project requirements centre on the customer ticket, payment/blocking state, assignment, maintenance execution, equipment/serial context, service history, parts/costs and resolution. The primary Support sidebar therefore stays intentionally compact.

The normal business-facing Support module contains:

- **Dashboard**
- **Tickets / Case Workspace**
- **Maintenance Requests**
- **Service Records**
- **Maintenance Schedules**
- **Field Service**
- **Equipment 360**
- **Service Policies** — one configuration workspace containing SLA Policies, SLA Calendars, Service Levels, Support Entitlements and Warranty Policies.

This matches the project workflow rather than presenting the application as a generic ITSM product.

### Optional advanced capability

The codebase also contains Teams, Skills, Queues, Routing Rules, Automation Rules and Knowledge Base. They are retained because they are valid extensions, but they are **not required for the core IERP Support/Maintenance business flow** and must not expand the normal sidebar.

When one of their rollout switches is enabled, these resources are grouped behind a single **Advanced Support** workspace. By default Smart Routing, Automation and Knowledge Base remain disabled.

Customer Support API and CSAT are channel capabilities rather than admin sidebar destinations. They may be enabled independently when the customer application requires them.

All resources remain registered in `AdminModuleRegistry` / Filament so authorization and direct-route feature gating stay enforceable.

The Support dashboard follows the [module dashboard layout](../../architecture/SYSTEM_OVERVIEW.md#module-dashboards). It provides ticket volume/queue/SLA indicators, average first-response time, optional CSAT, trend and service-economics widgets, work queues, and upcoming maintenance. Deeper quality measures such as reopen rate stay in Support Reports.

The Tickets and Maintenance Requests lists use the standard list-table experience ([ADR 0014](../../adr/0014-standard-list-table-experience.md)): view tabs, per-user favorites, Group by and slide-over rule filters.

## Ticket Case Workspace

The ticket detail page is the canonical agent workspace.

It combines:

- ticket stage, blocker, next action and SLA state;
- chronological public replies and internal notes;
- customer, assignment/team, service path and equipment/warranty context;
- diagnostic-payment state when applicable;
- linked maintenance jobs and private attachments;
- knowledge suggestions when Knowledge Base is enabled.

The first response is the first public non-customer reply. Internal notes never consume first-response SLA and are never returned through customer APIs.

## SLA v2 and Entitlements

SLA policy resolution may consider priority, ticket type, service path, support service level, customer, product variant and support team.

The **Service Policies** workspace lets Support Managers maintain the SLA configuration administratively (permissions `support.sla-calendar.*`, `support.service-level.*`, `support.entitlement.*`; gated by `SUPPORT_SLA_V2_ENABLED`):

- **SLA Calendars** — timezone, 24x7, default (exactly one) and active flags, weekly working periods (several windows per weekday allowed, never overlapping) and holiday/working-day exceptions (one per date). The default calendar cannot be deleted.
- **Service Levels** — stable code, name, description, active state; a level that has entitlements cannot be deleted.
- **Support Entitlements** — customer, service level, optional equipment currently in that customer's custody, validity dates, status, external (contract/PO) reference and notes. The form never changes Inventory custody.

If no active policy matches a ticket, intake does not fail: the ticket uses the built-in priority targets (legacy clocks) and a `support.sla.no_matching_policy` warning is logged.

Business calendars/exceptions define working time. Ticket milestone snapshots preserve the target applied at the time the ticket entered the relevant stage.

Legacy summary fields on `tickets` remain compatibility projections for existing tables/widgets/reports.

## Teams, Queues and Routing

Support teams contain employees with capacity, routing weight and remote/on-site eligibility. Skills may be attached to employees and required by routing rules.

When Smart Routing is enabled, deterministic rule precedence chooses a team and may auto-assign the least-loaded eligible member while respecting skill/capacity. Manual assignment remains supported and routing/assignment history remains auditable. Routing never overrides an existing assignee, ignores inactive rules/teams/members/employees, and leaves the ticket in the team queue when nobody is eligible.

## Safe Automation

Support automation uses fixed events, structured conditions and allow-listed actions. It does not execute arbitrary code.

All actions of one rule run in a single database transaction (a failing action rolls back the rule's earlier actions and the run is recorded as failed); malformed or unsupported conditions/actions fail the run without side effects. Automation runs are recorded with event identifiers for replay/idempotency protection. The scheduler emits the stale waiting-customer event hourly while automation is enabled.

## Field Service

On-site work remains attached to existing service records through `ServiceAppointment`.

Appointments capture:

- technician;
- planned start/end and estimated duration;
- address/location snapshot;
- dispatched/en-route/on-site/completed lifecycle;
- check-in/check-out coordinates;
- evidence and customer sign-off.

The Field Service board lists live visits only; soft-deleted appointments are not shown.

Overlapping appointments for one technician are rejected. Scheduling, rescheduling, dispatching and cancelling are dispatcher actions (`support.service-appointment.manage`); the assigned technician with `support.service-appointment.execute` can mark en route, check in and complete the visit.

## Equipment 360

Equipment 360 is a read-only Support projection around `SerializedInventoryUnit`.

It brings together customer custody, product/serial identity, warranty/support entitlement, active tickets, service history, reliability signals, service cost and recovery context.

The *Installation & Commissioning* section shows the unit's installation status, installer, commissioning and customer-acceptance outcome (including failure/rejection reasons), the delivery shipment, the owning maintenance request, evidence files for users who may view the installation, and the warranty activation result.

The *Calibration* section shows the last finished calibration (result, certificate and expiry, next due date, technician or external provider, measurements and, for users who may view calibrations, evidence files), any open calibration request with its progress, and the next preventive, inspection and calibration due dates from the unit's active schedules.

When the loaner or supplier-repair features are on, Equipment 360 also shows the temporary replacement (loaner serial, status, issued and returned dates) and, on a loaner unit, its loan history; and the supplier repair with its status, plus *Replaced by* on the original unit and *Replacement for* on the replacement.

The *Warranty & service* card shows the support entitlement (service level and end date) that is active today for the unit, or states that none is active; entitlements are maintained in Service Policies and never change custody.

Inventory remains the owner of serialized custody and stock truth.

## Customer Support API

Customer support routes are under `/api/customer`, authenticated with Sanctum and gated by `SUPPORT_CUSTOMER_API_ENABLED`.

The authenticated customer identity is derived from `User → CustomerProfile`; the client cannot choose another `customer_id`.

Current Support API capabilities include:

- ticket list/create/detail;
- public conversation list/reply;
- authorized private ticket attachment download;
- diagnostic-payment session/status;
- customer-owned equipment list/detail;
- customer maintenance list/detail;
- customer-visible Knowledge Base and pre-ticket suggestions;
- one post-close CSAT response per ticket.

Internal notes, internal cost/accounting fields and other customers' records are excluded.

## Knowledge Base

Knowledge articles have draft/published/archived states and internal/customer/both visibility.

Articles may target ticket types and products/variants. Suggestions are deterministic and prioritize those matches plus issue keywords.

Only published customer-visible articles may be shared into the customer conversation or returned by customer APIs. In the Case Workspace the agent sees suggested articles and can share one with the customer or mark it as used in the resolution; a shared article stops being exposed if it is later unpublished or made internal.

## Reporting

Support Reports include:

- current workload and backlog aging;
- first-response and resolution timing;
- SLA breach/compliance metrics;
- reopen rate and CSAT;
- active assignment load;
- maintenance/parts indicators;
- technician scheduled and actual on-site time;
- repeat-failure signals;
- service margin;
- warranty recovery performance;
- preventive-maintenance compliance.

No technician utilization percentage is produced until authoritative working-capacity data exists.

## Boundary

- Inventory owns parts stock mutation and equipment custody truth.
- Payments owns provider transaction/payment behavior.
- Sales owns quotations/invoices created for chargeable maintenance.
- Accounting owns resulting ledger truth.
- Notifications owns delivery/template mechanics; Support emits domain events and variables.

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
