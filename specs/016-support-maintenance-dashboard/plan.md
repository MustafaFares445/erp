# Implementation Plan: Support and Maintenance Dashboard

**Branch**: `016-support-maintenance-dashboard` | **Date**: 2026-08-13 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/016-support-maintenance-dashboard/spec.md`

## 0. How to drive this with Spec Kit

State so far: `spec.md` was written and owner-approved alongside ADR 0004
(2026-08-10, `Docs/adr/0004-filament-support-maintenance-dashboard.md`), which
also bumped the constitution to 1.5.0. `/speckit-clarify` ran on 2026-08-13,
resolving three open technical unknowns (SLA clock semantics, SLA defaults,
parts-reversal granularity — see spec.md §Clarifications) and correcting one
stale reference (FR-104's `AuditLogger` → the actual `spatie/laravel-activitylog`
infra, ADR 0005). This document is this feature's `/speckit-plan` output:
`research.md` (Phase 0), `data-model.md` + `contracts/*.md` + `quickstart.md`
(Phase 1). Next: this plan's own §15 Work Packages feed `/speckit-tasks`,
which produces `tasks.md`; `/speckit-implement` then executes it.

## 1. Summary

Add a dashboard-only Support and Maintenance module — support tickets with
priority/SLA tracking and an admin-recorded payment hold, maintenance
requests with equipment/warranty data, and service records that can consume
spare parts through the existing Inventory services. Delivered entirely as
`/admin` Filament resources under two new fixed roles (`Support Manager`,
`Support Agent`) layered on the existing `DashboardRole`/Spatie Permission
infrastructure, per ADR 0004. Primary technical approach: reuse, in this
order of reliance, the existing numbering pattern (`OrderFulfillmentService`),
Inventory stock-movement services (`InventoryBalanceService`), Media Library
attachment pattern (`CustomerVisit`), audit pattern (`activity()`), and RBAC
pattern (`ChecksEmployeePermissions`) — no new cross-cutting mechanism is
introduced anywhere in this feature (research.md).

## 2. Verified pre-implementation state

| Item | State |
|---|---|
| Feature spec | `specs/016-support-maintenance-dashboard/spec.md`, clarified 2026-08-13 |
| Governance | ADR 0004 **Accepted** 2026-08-10; constitution **1.5.0**; no governance gate remains (contrast with spec 015, which had to produce its own ADR as part of planning — 016's is already done) |
| `AdminModuleRegistry` | `support` nav group already pinned (`app/Filament/AdminModuleRegistry.php:205-215`) referencing `TicketResource`, `MaintenanceRequestResource`, `ServiceRecordResource` — **none exist yet**; this plan's resource names are fixed by that existing registration, not chosen freely |
| `lang/en/admin.php` | `groups.support`, `resources.tickets`, `resources.maintenance_requests`, `resources.service_records` already present |
| Models/migrations/services for this feature | **None exist** — fully greenfield (`app/Services/Support/` does not exist) |
| `DashboardRole`, per-module permission enums, `Checks*Permissions` traits | Established patterns to extend, not redesign (research.md §4) |
| `InventoryBalanceService`, `InventoryMovement`, Media Library, numbering, `activity()` | Established patterns to call into unchanged (research.md §1–3, §5) |
| `Docs/database/ERD.md` | Still describes the pre-ADR-0004 baseline for the 6 Support tables; the constitution's Sync Impact Report carries an explicit Follow-up TODO to update it with the 4 authorized extensions **before implementation begins** — done as part of this plan (§8, then applied back to the canonical doc immediately after Phase 1) |
| `Docs/PRD.md` / `Docs/SDD.md` / `IMPLEMENTATION_PLAN.md` §13 | Confirmed silent on SLA specifics, business-hours semantics, and default targets (research.md §6) — resolved by `/speckit-clarify`, not left implicit |

## 3. Technical context

**Language/Version**: PHP 8.4

**Primary Dependencies**: Laravel 13, Filament 5, Livewire 3, Spatie Laravel
Permission, Spatie Laravel Media Library, `spatie/laravel-activitylog`. No new
package — in particular, no Spatie Media Library Filament plugin is added
(research.md §3: not installed today, and this feature doesn't need it).

**Storage**: MySQL (production/local via Laragon); SQLite `:memory:` for tests
— every new migration must work on both, matching every existing migration.

**Testing**: Pest 4, PHPUnit 12, PHPStan/Larastan 3 (no new baseline entries),
Pint, Rector, Xdebug coverage.

**Target Platform**: existing `/admin` Filament panel only (ADR 0004 — no API,
no customer app, no technician app).

**Project Type**: Laravel modular monolith with Filament admin resources.

**Constraints**:

- ADR 0004 is already approved — no governance blocker, but its scope
  boundary is absolute: no `/api/customer/*` or `/api/dashboard/*` route, no
  Stripe call, no journal/tax/revenue posting, no outbound notification, no
  knowledge base, no AI triage (spec.md §Scope "does not add");
- spare-parts consumption MUST route through `InventoryBalanceService` and
  produce exactly one `InventoryMovement` per consumption/reversal — never a
  direct `inventory_stocks` write (Principle III, NON-NEGOTIABLE);
- ticket attachments MUST use Media Library, never a bespoke file table
  (FR-103/104);
- every status transition MUST be enforced in a domain service, rejected
  identically whether invoked from Filament or directly (FR-006/008);
- adding `Support Manager`/`Support Agent` MUST NOT widen access to
  Inventory, CRM, or Employees (FR-009, SC-011);
- no second audit trail, permission store, or media store (FR-104);
- no new `phpstan-baseline.neon` entries; baseline may only shrink;
- 100% type coverage and 100% code coverage must both stay at 100% (SC-014);
- SLA clock is continuous calendar time, no business-hours calendar
  (clarification, 2026-08-13);
- English-only UI strings, matching spec 013/015 precedent (D7);
- `Docs/database/ERD.md` must reflect the 4 ADR-0004 extensions before any
  migration is written (Principle I).

---

## 4. Scope

**In scope** (spec.md §Scope, ADR 0004 "Authorised" list): ticket intake,
classification, numbering, triage; assignment history and the full status
lifecycle; the conversation thread and attachments; chargeable tickets held
at `pending_payment` and released by admin-recorded settlement; priority, SLA
tracking, clock pausing, breach visibility; maintenance requests (from a
ticket or standalone) with equipment/warranty link; service records with
technician/due-date/status; spare-parts consumption posting through existing
Inventory services; search/filter/report/audit; the module's fixed roles and
permissions.

**Out of scope** — and not authorised by ADR 0004: `/api/customer/*`,
`/api/dashboard/*`, or any other API surface; the customer mobile
application; a technician mobile application; customer self-service ticket
creation; Stripe integration or provider-generated payment links; any
journal entry, tax recognition, revenue posting, or other accounting side
effect from a ticket payment; outbound customer notification delivery
(email/SMS/push); a knowledge base, canned responses, or chat/telephony
integration; automatic ticket routing or AI triage. Implementing any of these
later requires its own specification and either a separate ADR or an
explicit amendment to ADR 0004.

Because ticket intake belongs to the out-of-scope customer app, every ticket
and maintenance request in this feature is created and administered **from
the dashboard on the customer's behalf** (D1) — this is not customer
self-service and never will be without its own spec/ADR.

---

## 5. Constitution check

*GATE: must pass before Phase 0 research; re-check after Phase 1 design.*

| Principle | Result | Treatment |
|---|---|---|
| I. Specification-First | **Pass** | `spec.md` is clarified and owner-approved; ADR 0004 is Accepted; constitution is 1.5.0. The Sync Impact Report's Follow-up TODO — reflect the 4 ERD extensions into `Docs/database/ERD.md` before implementation begins — is **discharged**: `Docs/database/ERD.md` now carries all 4 extensions (extended `tickets`/`maintenance_records` columns, new `sla_policies`/`service_record_parts` tables, the `ticket_attachments`→Media Library deviation note, updated enum catalog, entity list, and Mermaid diagram), completed as this plan's own WP0 immediately after Phase 1, before any migration is written. |
| II. Domain-Driven Modular Monolith | Pass by design | All rules in `app/Services/Support/*`; Filament resources are thin adapters, mirroring `Crm`/`Employees`/`Inventory`/`Orders`. Status transitions live on enums + services (contracts/ticket-lifecycle.md, contracts/maintenance-lifecycle.md), never only in the UI. |
| III. Financial & Inventory Integrity (NON-NEGOTIABLE) | **Pass — narrow write, existing path only** | The only stock-changing action (spare-parts consumption/reversal) calls `InventoryBalanceService::transferOut()`/`transferIn()` — the existing `product_variant_id`+`warehouse_id` source of truth — inside a transaction, producing exactly one `InventoryMovement` each way (research.md §2, contracts/maintenance-lifecycle.md §4). No accounting/tax/journal path exists in this module at all (D4/FR-046/SC-004). |
| IV. Unified Access, Media & Payment | **Pass after this plan** | Ticket attachments use Media Library (`ticket-attachments` collection), not a bespoke table (research.md §3). Authorization uses Spatie Permission exclusively, extending — not duplicating — `DashboardRole`/`Checks*Permissions`. Manual payment settlement (no Stripe) is explicitly the only supported payment channel for this feature, matching D4; no divergent code path is created because no second path exists to diverge from. |
| V. AI Isolation & Human Oversight | **N/A** | This feature introduces no AI processing. |
| VI. Engineering Discipline | Pass when §15's Verification work package completes | Thin Filament adapters; typed, self-checking domain services (research.md §4 — stricter than the Employees precedent by design); Form Requests/Filament schemas for validation; transactions on every multi-row write; queues not required (no long-running operation in this feature); tests per rule; audit on every sensitive action (contracts/audit-log.md). |

No Complexity Tracking entries — every principle passes without a
justified violation.

---

## Project Structure

### Documentation (this feature)

```text
specs/016-support-maintenance-dashboard/
├── plan.md              # This file
├── spec.md              # Feature specification (clarified 2026-08-13)
├── research.md           # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md         # Phase 1 output
├── contracts/            # Phase 1 output
│   ├── permissions.md
│   ├── ticket-lifecycle.md
│   ├── maintenance-lifecycle.md
│   └── audit-log.md
└── tasks.md              # Phase 2 output (/speckit-tasks — NOT created here)
```

### Source Code (repository root)

```text
app/
├── Enums/
│   ├── TicketType.php                        # NEW — software_issue|hardware_issue|general_support|maintenance_request
│   ├── TicketPriority.php                    # NEW — Low|Normal|High|Urgent
│   ├── TicketStatus.php                      # NEW — 9 cases, allowedTransitions()/canTransitionTo() (contracts/ticket-lifecycle.md §1)
│   ├── PaymentLinkStatus.php                 # NEW — Pending|Settled|Cancelled
│   ├── WarrantyStatus.php                    # NEW — Covered|Expired|Unknown
│   ├── MaintenanceStatus.php                 # NEW — Open|InProgress|Closed|Cancelled (shared, contracts/maintenance-lifecycle.md §1)
│   ├── SupportPermission.php                 # NEW — support.* permission catalogue (NO fixedRoleNames())
│   ├── MovementType.php                      # MODIFIED — +1 case: ServiceConsumption
│   └── DashboardRole.php                     # MODIFIED — +2 cases: SupportManager, SupportAgent
├── Models/
│   ├── Ticket.php                             # NEW — HasMedia (ticket-attachments collection)
│   ├── TicketMessage.php                      # NEW — append-only guard
│   ├── TicketAssignment.php                   # NEW — append-only guard
│   ├── TicketPaymentLink.php                  # NEW
│   ├── SlaPolicy.php                          # NEW
│   ├── MaintenanceRecord.php                  # NEW — "Maintenance Request"
│   ├── MaintenanceTask.php                    # NEW — "Service Record"
│   └── ServiceRecordPart.php                  # NEW — immutable once written (except reversed_* columns)
├── Services/Support/
│   ├── TicketIntakeService.php                 # NEW — create/update classification, numbering (contracts/ticket-lifecycle.md §2)
│   ├── TicketAttachmentSynchronizer.php         # NEW — mirrors ProductMediaSynchronizer
│   ├── TicketLifecycleService.php               # NEW — transitions, assignment, closure guard (§1, §3)
│   ├── TicketMessageService.php                 # NEW — conversation + first-response (§4)
│   ├── TicketPaymentService.php                 # NEW — chargeable creation + settlement (§5)
│   ├── SlaService.php                           # NEW — clock start/pause/resume/recompute (§6)
│   ├── MaintenanceRecordService.php              # NEW — creation, equipment link, warranty, transitions
│   ├── ServiceRecordService.php                  # NEW — creation, due-date rule, cascade, transitions
│   ├── ServiceRecordPartService.php               # NEW — consume/reverse via InventoryBalanceService
│   └── SupportReportService.php                  # NEW — workload/SLA/maintenance/parts aggregates (self-checking, mirrors EmployeeReportService)
├── Http/Controllers/
│   └── TicketMediaController.php                # NEW — signed, Gate::authorize('view', $ticket), mirrors VisitMediaController
├── Policies/
│   ├── TicketPolicy.php                          # NEW
│   ├── MaintenanceRecordPolicy.php               # NEW
│   ├── MaintenanceTaskPolicy.php                 # NEW
│   ├── SlaPolicyPolicy.php                       # NEW
│   └── Concerns/ChecksSupportPermissions.php     # NEW — mirrors ChecksEmployeePermissions
└── Filament/
    ├── AdminModuleRegistry.php                   # unchanged — support group already pinned
    └── Resources/
        ├── Tickets/TicketResource.php                       # NEW (class already pinned)
        ├── MaintenanceRequests/MaintenanceRequestResource.php  # NEW (class already pinned)
        ├── ServiceRecords/ServiceRecordResource.php          # NEW (class already pinned)
        ├── SlaPolicies/SlaPolicyResource.php                 # NEW — List + Edit only, no Create/Delete (4 fixed rows)
        └── SupportReports/SupportReportResource.php          # NEW — mirrors EmployeeReportResource

database/
├── migrations/                                   # NEW — 8 tables (data-model.md), migration tests first
└── seeders/
    ├── SupportPermissionSeeder.php                # NEW — mirrors EmployeePermissionSeeder
    └── SlaPolicySeeder.php                        # NEW — 4 rows, defaults per research.md §7

tests/Feature/Support/                            # NEW — one suite per user story (spec.md US1–US9)
tests/Unit/Enums/                                  # NEW — allowedTransitions()/canTransitionTo() per contracts/*.md
lang/en/admin.php                                  # unchanged — support labels already present; add SLA/report section labels only
Docs/database/ERD.md                                # MODIFIED — 4 ADR-0004 extensions applied (this plan's Phase 1, done immediately, per Principle I)
```

**Structure Decision**: The existing layout is kept exactly — domain services
in a new `app/Services/Support/` folder beside `Crm`, `Employees`,
`Inventory`, `Orders`, and `Identity` (Principle II); Filament resources in
`app/Filament/Resources/<Plural>/` with `Pages/`, `Tables/`, `Schemas/`, and
`RelationManagers/` subfolders (data-model.md's relations — e.g.
`MessagesRelationManager`/`AssignmentsRelationManager` under `TicketResource`,
`ServiceRecordsRelationManager` under `MaintenanceRequestResource`,
`ConsumedPartsRelationManager` under `ServiceRecordResource`); models flat in
`app/Models`. No new top-level directory. `AdminModuleRegistry` and
`lang/en/admin.php` are already in place and are not modified — only the
resource classes they already reference are created, exactly as spec 015 did
for the Employees group.

## Complexity Tracking

*(intentionally empty — no Constitution Check violation requires
justification; see §5)*

---

## 6. Requirement traceability

| FR range | Topic | Where enforced |
|---|---|---|
| FR-001–009 | Roles and authorization | contracts/permissions.md, `Checks*Permissions`, every service's self-check |
| FR-010–017 | Ticket intake and classification | contracts/ticket-lifecycle.md §2, `TicketIntakeService` |
| FR-020–028 | Lifecycle and assignment | contracts/ticket-lifecycle.md §1/§3, `TicketLifecycleService` |
| FR-030–035 | Conversation and attachments | contracts/ticket-lifecycle.md §4, `TicketMessageService`, `TicketAttachmentSynchronizer` |
| FR-040–048 | Paid tickets | contracts/ticket-lifecycle.md §5, `TicketPaymentService` |
| FR-050–058 | Priority and SLA | contracts/ticket-lifecycle.md §6, `SlaService` |
| FR-060–068 | Maintenance requests, equipment, warranty | contracts/maintenance-lifecycle.md §2, `MaintenanceRecordService` |
| FR-070–077 | Service records | contracts/maintenance-lifecycle.md §3, `ServiceRecordService` |
| FR-080–088 | Spare-parts consumption | contracts/maintenance-lifecycle.md §4, `ServiceRecordPartService` |
| FR-090–094 | Search, reports, audit | `SupportReportService`, contracts/audit-log.md |
| FR-100–107 | General (validation, transactions, media, permission catalogue, English-only, pagination) | cross-cutting — every service and resource |

## 7. Domain model overview

See [data-model.md](./data-model.md) §Domain overview for the full ASCII
relationship diagram. Summary: `CustomerProfile` roots both `Ticket` and
(when standalone) `MaintenanceRecord`; a `Ticket` optionally roots a
`MaintenanceRecord` too (FR-060); `MaintenanceRecord` roots `MaintenanceTask`
("Service Record"); `MaintenanceTask` roots `ServiceRecordPart`, the only
table reaching outside this module's domain (`ProductVariant`, `Warehouse`,
`InventoryMovement`). `SlaPolicy` is 4 standalone seeded rows, read only at
ticket clock-start/priority-change, never joined live.

## 8. Target data model

Full column-level detail lives in [data-model.md](./data-model.md) — 8
tables: 6 from the ERD's Support section (`tickets`, `ticket_messages`,
`ticket_assignments`, `ticket_payment_links`, `maintenance_records`,
`maintenance_tasks`), extended per ADR 0004's 4 authorized deltas, plus 2 new
tables the ADR also authorizes (`sla_policies`, `service_record_parts`).
`ticket_attachments` is **not** created (Media Library instead,
data-model.md §Media collections). One `MovementType` enum case is added;
`DashboardRole` gains two cases. No existing table is modified.

**ERD reconciliation (Principle I, this plan's own action item)**: immediately
following this document, `Docs/database/ERD.md`'s `tickets`,
`maintenance_records` sections gain the 4 ADR-0004 columns/tables described
above, and its `ticket_attachments` table description gains a note that this
feature realizes it via Media Library rather than a bespoke table — matching
how the ERD documents the equivalent Employees-module deviation. This closes
the constitution's Follow-up TODO before any migration file is written.

## 9. Domain services and contracts

| Service | Contract |
|---|---|
| `TicketIntakeService`, `TicketAttachmentSynchronizer` | [contracts/ticket-lifecycle.md](./contracts/ticket-lifecycle.md) §2 |
| `TicketLifecycleService` | §1, §3 |
| `TicketMessageService` | §4 |
| `TicketPaymentService` | §5 |
| `SlaService` | §6 |
| `MaintenanceRecordService` | [contracts/maintenance-lifecycle.md](./contracts/maintenance-lifecycle.md) §1–2 |
| `ServiceRecordService` | §1, §3 |
| `ServiceRecordPartService` | §4 |
| `SupportReportService` | FR-090–094; self-checks like `EmployeeReportService` (research.md §4) |

Every service is constructor-injected where it depends on another (e.g.
`ServiceRecordPartService` depends on `InventoryBalanceService`), never
service-locates; every multi-row write is one `DB::transaction()`; every
service method that mutates state takes an explicit `User $actor` parameter
and never calls `auth()->user()` internally (matching every existing
Inventory/Employees service).

## 10. Authorization

Full catalogue and role matrix: [contracts/permissions.md](./contracts/permissions.md).
Summary: 4 fixed roles (`System Admin`, `Support Manager`, `Support Agent`,
`Reviewer`), 17 `support.*` permissions, `ChecksSupportPermissions` trait
mirroring `ChecksEmployeePermissions`'s `isAdmin()`-bypass-with-fixed-role-
exception shape. Two ownership-scoped permissions (`ticket.work`,
`service-record.execute`, plus the ownership half of `parts.consume`) require
the policy to additionally compare the record's current assignee against the
acting user's `EmployeeProfile` — permission-string possession alone is
necessary but not sufficient for those three abilities. Every domain service
self-checks in addition to Filament's own `->authorize()` calls, closing the
direct-service-call gap the Employees module left open (research.md §4) —
this is the one place 016 is deliberately stricter than its closest
precedent, driven by spec.md's own explicit requirement (FR-006/008).

## 11. Dashboard surface

Five Filament resources, all under the already-pinned `support` nav group:
`TicketResource` (full CRUD + `Messages`/`Assignments` relation managers +
attachment upload), `MaintenanceRequestResource` (full CRUD + `ServiceRecords`
relation manager), `ServiceRecordResource` (standalone list/view for
cross-request search per FR-090, plus a `ConsumedParts` relation manager on
its view page), `SlaPolicyResource` (List + Edit only — 4 fixed rows, no
Create/Delete), `SupportReportResource` (mirrors `EmployeeReportResource`).
Every mutating `Action`/`BulkAction` in every `*Table.php` carries
`->authorize('ability')`, matching `EmployeesTable.php`'s pattern exactly
(research.md §3/§4). File serving for `ticket-attachments` goes through the
new `TicketMediaController`, not a public media URL.

## 12. Inventory integration design (Principle III checkpoint)

The one place this module writes outside its own domain. `ServiceRecordPartService`
is the single call site: `consume()` calls
`InventoryBalanceService::transferOut()` then creates one `InventoryMovement`
(`ServiceConsumption`, negative quantity, `source_type = 'service_record_part'`);
`reverse()` calls `transferIn()` (always the full original quantity — no
partial parameter exists) then creates one compensating `InventoryMovement`
(positive quantity, same `source_type`/`source_id`). Both paths run inside one
transaction each; both are covered by contracts/maintenance-lifecycle.md §4
and its SC-008/SC-009 test obligations. No Support role gains any Inventory
dashboard access as a side effect (FR-088, SC-011) — `parts.consume`/
`parts.reverse` are `support.*` permissions, checked by Support's own policies
only; nothing grants `inventory.*` permissions or bypasses `ChecksInventoryPermissions`.

## 13. Audit, reports, localization

Audit: [contracts/audit-log.md](./contracts/audit-log.md) — the shared
`activity()` helper, `support.{entity}.{verb}` action strings, one call site
per sensitive action inside its own service's transaction. Reports:
`SupportReportService` computes open-ticket workload (by status/priority/
assignee), SLA breach counts and average resolution time per period, open
maintenance requests, overdue service records, and parts consumed per period
(FR-091–093) — read-only aggregates with no backing table
(data-model.md's `Support Report` key entity has none by design).
Localization: English-only for this phase (D7/FR-106), matching spec 013/015.

## 14. Settled decisions

**Owner decisions D1–D7** (spec.md §Owner Decisions, approved 2026-08-10):
dashboard-only surface (D1); Service Records = `maintenance_tasks` (D2);
priority/SLA in scope (D3); paid tickets without Stripe (D4); equipment/
warranty link (D5); spare-parts consumption in scope (D6); English-only UI
(D7).

**Clarifications** (spec.md §Clarifications, 2026-08-13): SLA clock is
continuous calendar time; default targets Urgent 1h/4h, High 4h/24h, Normal
8h/48h, Low 24h/72h; spare-parts reversal is full-quantity only.

**This plan's own resolutions** (research.md): `TCK-######` numbering
mirroring `OrderFulfillmentService`; parts consumption through
`InventoryBalanceService` with one new `MovementType` case; Media Library over
a bespoke attachments table; self-checking domain services (stricter than the
Employees precedent); the exact `support.*` permission catalogue and role
matrix, including which three abilities are settlement/reversal-restricted to
System Admin only.

## 15. Work packages

### WP0 — ERD reconciliation *(blocking, discharges Principle I)*
Apply the 4 ADR-0004 extensions to `Docs/database/ERD.md` (§8's reconciliation
note). No code in this package.

### WP1 — Schema, enums, permissions
8 migrations (data-model.md); all 9 new enums + the 2 modified ones;
`SupportPermission`, `ChecksSupportPermissions`, `SupportPermissionSeeder`,
`SlaPolicySeeder`; `DashboardRole` cross-module regression tests (extend the
*existing* Employees/CRM/Inventory `CrossModulePermissionLeakTest` files, per
contracts/permissions.md's Verification section).

### WP2 — Models, factories, policies
8 models (`Ticket` with `HasMedia`; append-only guards on `TicketMessage`/
`TicketAssignment`/`ServiceRecordPart`); factories with meaningful states;
4 policies + `ChecksSupportPermissions`.

### WP3 — Ticket intake, lifecycle, conversation (US1–US3, P1)
`TicketIntakeService`, `TicketAttachmentSynchronizer`,
`TicketLifecycleService`, `TicketMessageService`, `TicketResource` + Pages/
Schemas/Tables/RelationManagers, `TicketMediaController`.

### WP4 — Paid tickets and SLA (US4–US5, P1/P2)
`TicketPaymentService`, `SlaService`, `SlaPolicyResource`.

### WP5 — Maintenance requests and service records (US6–US7, P2)
`MaintenanceRecordService`, `ServiceRecordService`,
`MaintenanceRequestResource`, `ServiceRecordResource` + relation managers.

### WP6 — Spare-parts consumption (US8, P2)
`ServiceRecordPartService`, `ConsumedPartsRelationManager`, the new
`MovementType::ServiceConsumption` case.

### WP7 — Reports and audit (US9, P3)
`SupportReportService`, `SupportReportResource`, audit-view wiring.

### WP8 — Verification and delivery gate
`composer test` green, no PHPStan baseline growth, 100% type/code coverage
held, quickstart.md walked end to end, cross-module leak tests extended in
both directions (contracts/permissions.md).

### 15.1 Dependency order

WP0 → WP1 → WP2 → WP3 → WP4 → WP5 → WP6 → WP7 → WP8, matching the spec's own
P1→P2→P3 story priority (US1's roles/permissions must exist before any other
story's authorization checks can be tested meaningfully; US2/US3/US4 (all P1)
before US5–US8 (P2); US9 (P3) last, since its reports read data every other
story produces).

## 16. Tasks skeleton

`/speckit-tasks` expands each work package into numbered, dependency-ordered
tasks (`[T0xx] [P?] [USn] Description`), tests-before-implementation per user
story, mirroring `specs/015-employees-plans-visits-dashboard/tasks.md`'s
format exactly. Not duplicated here — this section exists only to confirm the
work-package boundaries above are the right granularity for that expansion.

## 17. Testing strategy

Pest feature tests under `tests/Feature/Support/`, one suite per user story;
unit tests under `tests/Unit/Enums/` for every `allowedTransitions()`/
`canTransitionTo()` pair (contracts/ticket-lifecycle.md §1,
contracts/maintenance-lifecycle.md §1); `tests/Unit/ArchTest.php` extended to
ban a Filament Resource from writing `inventory_stocks`/`inventory_movements`
directly (must go through `InventoryBalanceService`) and to ban any Support
service from calling `auth()->user()` internally. Every SC-0xx success
criterion in spec.md maps to at least one test named in a contract's Test
obligations section.

## 18. Migration and rollback

All 8 migrations are additive (new tables only); every `down()` drops exactly
what its `up()` created, in reverse FK order. No existing table's schema
changes, so no existing feature's migration needs a corresponding down-path
review. `SlaPolicySeeder` is idempotent (`updateOrCreate` on `priority`) so
re-running it after a schema rollback-and-forward cycle never duplicates rows.

## 19. Explicit non-deliverables

Everything ADR 0004 marks "not authorised" (§3/§4 above) plus, staying inside
scope but explicitly not built in this pass per the spec's own Assumptions:
automatic SLA-breach notification delivery (dashboard-visible flags only);
any business-hours/holiday calendar; a knowledge base or canned-response
library; partial spare-parts reversal.

## 20. Completion definition

This feature is complete when: every FR in spec.md has a passing test named
in a contract's Test obligations section; every SC-0xx is verified per
quickstart.md; `composer test` is green with no PHPStan baseline growth and
100% type/code coverage held; `Docs/database/ERD.md` reflects the 4 ADR-0004
extensions; and the cross-module leak guarantee (FR-009/SC-011) is verified
in both directions (Support roles denied elsewhere; other modules' fixed
roles denied in Support).
