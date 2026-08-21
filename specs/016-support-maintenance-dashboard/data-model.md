# Phase 1 Data Model: Support and Maintenance Dashboard

**Feature**: `specs/016-support-maintenance-dashboard` | **Date**: 2026-08-13

8 tables: 6 from `Docs/database/ERD.md`'s Support section (`tickets`,
`ticket_messages`, `ticket_assignments`, `ticket_payment_links`,
`maintenance_records`, `maintenance_tasks`) plus the 2 new tables ADR 0004
authorizes (`sla_policies`, `service_record_parts`). `ticket_attachments` is
**not** created — Media Library replaces it (research.md §3). Every delta from
the ERD's baseline column set is marked **delta**; anything not marked
matches `Docs/database/ERD.md` as written. All 8 tables are new and additive —
no existing table is modified.

Conventions applied throughout, matching existing migrations:
`foreignId()->constrained()` with an explicit delete behavior; blameable
`created_by`/`updated_by` nullable FKs + `TracksBlameable`; `softDeletes()`
where the business rule requires archive-not-delete (FR-015/067); money as
`decimal(15,2)`; stock quantities as `decimal(15,3)` (matching
`stock_transfer_items.quantity`); every FK indexed, plus `status` and any
date/timestamp column used in filtering or breach computation.

State-transition rules for every status enum live in
[contracts/ticket-lifecycle.md](./contracts/ticket-lifecycle.md) and
[contracts/maintenance-lifecycle.md](./contracts/maintenance-lifecycle.md)
rather than being repeated here. The permission catalogue and role matrix live
in [contracts/permissions.md](./contracts/permissions.md). The audit-log
shape lives in [contracts/audit-log.md](./contracts/audit-log.md).

---

## Domain overview

```text
CustomerProfile ─1:N─ Ticket ─1:N── TicketMessage (append-only)
                         │      ├─1:N── TicketAssignment (append-only; current assignee = newest row)
                         │      ├─1:1── TicketPaymentLink (only when is_chargeable)
                         │      ├─ media: ticket-attachments (Media Library, no bespoke table)
                         │      ├─N:1── Ticket (continued_from_ticket_id, nullable self-reference)
                         │      └─1:N── MaintenanceRecord (raised from this ticket)
                         └─1:N── MaintenanceRecord (standalone; ticket_id null)
                                      ├─N:1── SerializedInventoryUnit (nullable equipment link)
                                      └─1:N── MaintenanceTask  ("Service Record")
                                                   ├─N:1── EmployeeProfile (assigned technician, nullable)
                                                   └─1:N── ServiceRecordPart
                                                                ├─N:1── ProductVariant
                                                                ├─N:1── Warehouse
                                                                ├─N:1── InventoryMovement (consumption)
                                                                └─N:1── InventoryMovement (reversal, nullable)

SlaPolicy — 4 fixed rows (one per TicketPriority), no FK from Ticket; targets
are copied onto the ticket at clock-start (FR-053), never joined live.
```

A `MaintenanceRecord` reaches its `Ticket` only through the nullable `ticket_id`
(standalone requests have none, FR-061). A `MaintenanceTask` never moves
between `MaintenanceRecord`s (FR-071) and has no relationship to `Ticket`
directly. `ServiceRecordPart` is the only table that reaches outside this
module's domain (into `ProductVariant`/`Warehouse`/`InventoryMovement`), and it
never writes to `inventory_stocks` itself (research.md §2).

---

## 1. `tickets`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `ticket_number` | string(100) | no | unique **including against soft-deleted rows** (`withTrashed()` on generation, mirroring `OrderFulfillmentService`); format `TCK-######` (research.md §1) |
| `customer_id` | fk → `customer_profiles` | no | restrictOnDelete |
| `assigned_employee_id` | fk → `employee_profiles` | yes | nullOnDelete; always reflects the newest `TicketAssignment` row (FR-024) — never written outside `TicketLifecycleService::assign()` |
| `type` | string(30) | no | cast to `TicketType` |
| `priority` | string(20) | no | **delta (D3/ADR 0004 ext. 1)** — cast to `TicketPriority`; absent from the ERD baseline |
| `title` | string(255) | no | |
| `description` | text | no | |
| `status` | string(30) | no | cast to `TicketStatus`; set by the creating service per FR-021 (`pending` or `pending_payment`), never a bare DB default |
| `pending_reason` | string(100) | yes | set while `status = pending_payment` (FR-041) |
| `is_chargeable` | boolean | no | default `false` — **delta**; implied by D4/FR-040 but not an explicit ERD column |
| `continued_from_ticket_id` | fk → `tickets` | yes | **delta (ADR 0004 ext. 1)** — nullOnDelete self-reference (FR-017) |
| `sla_response_target_minutes` | unsigned int | yes | **delta (ADR 0004 ext. 1)** — snapshotted from `sla_policies` at clock-start (FR-053); null before `live` |
| `sla_resolution_target_minutes` | unsigned int | yes | **delta (ADR 0004 ext. 1)** — snapshotted likewise |
| `live_at` | timestamp | yes | **delta (ADR 0004 ext. 1)** — when the ticket first reached `live`; the SLA clock start (FR-052) |
| `response_due_at` | timestamp | yes | **delta (ADR 0004 ext. 1)** — `live_at + sla_response_target_minutes` |
| `resolution_due_at` | timestamp | yes | **delta (ADR 0004 ext. 1)** — `live_at + sla_resolution_target_minutes`, extended by `waiting_customer_accumulated_seconds` on each return to work (FR-055) |
| `first_response_at` | timestamp | yes | **delta (ADR 0004 ext. 1)** — set once by the first customer-visible agent message (FR-033/034); never overwritten |
| `resolved_at` | timestamp | yes | **delta (ADR 0004 ext. 1)** — set entering `resolved`, cleared on reopen (FR-025) |
| `response_breached` | boolean | no | **delta (ADR 0004 ext. 1)** — default `false`; sticky once `true` (FR-057) |
| `resolution_breached` | boolean | no | **delta (ADR 0004 ext. 1)** — default `false`; sticky once `true` |
| `waiting_customer_since` | timestamp | yes | **delta (ADR 0004 ext. 1)** — non-null only while `status = waiting_customer` |
| `waiting_customer_accumulated_seconds` | unsigned int | no | **delta (ADR 0004 ext. 1)** — default `0`; running total across every past wait (FR-055) |
| `created_by` / `updated_by` | fk → `users` | yes | `TracksBlameable` |
| timestamps, `softDeletes` | | | archiving (FR-015) is a soft delete, never physical |

**Relationships**: `belongsTo CustomerProfile`; `belongsTo EmployeeProfile`
(`assignedEmployee`); `belongsTo Ticket` (`continuedFromTicket`); `hasMany
TicketMessage`; `hasMany TicketAssignment`; `hasOne TicketPaymentLink`;
`hasMany MaintenanceRecord`; media collection `ticket-attachments`.

**Validation**: `customer_id`/`type`/`priority`/`title`/`description`
required (FR-010); `is_chargeable = true` requires an `amount` and `currency`,
validated on the `TicketPaymentLink` the same service creates in the same
transaction, not on `Ticket` itself; initial `status` follows `is_chargeable`
exactly (FR-021) — never set independently by the caller.

---

## 2. `ticket_messages`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `ticket_id` | fk → `tickets` | no | cascadeOnDelete (soft-deleted with the ticket, never independently) |
| `sender_user_id` | fk → `users` | no | restrictOnDelete |
| `message` | text | no | |
| `is_internal_note` | boolean | no | **delta** — default `false` (FR-031/034) |
| `created_at` | timestamp | no | no `updated_at` — append-only (FR-032), enforced by a model-level guard rejecting any update, mirroring `TaskStatusLog`/`VisitGpsLog` |

**Relationships**: `belongsTo Ticket`; `belongsTo User` (`sender`).

**Validation**: append-only at every layer — no update/delete path exists in
the service, policy, or Filament layer.

---

## 3. `ticket_assignments`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `ticket_id` | fk → `tickets` | no | cascadeOnDelete |
| `employee_id` | fk → `employee_profiles` | no | restrictOnDelete |
| `assigned_by` | fk → `users` | no | restrictOnDelete |
| `assigned_at` | timestamp | no | |
| `created_at` | timestamp | no | no `updated_at` — append-only (FR-023), same guard as `TicketMessage` |

**Deviation from the ERD baseline**: `Docs/database/ERD.md` lists a `status`
column on `ticket_assignments` (the generic "draft/pending" placeholder
repeated across most ERD tables). Dropped here — an append-only assignment
history has no status of its own; FR-023 never describes one, and adding one
would reintroduce exactly the "which assignment is current" ambiguity FR-024
rules out by definition (current assignee = newest row, full stop).

**Relationships**: `belongsTo Ticket`; `belongsTo EmployeeProfile`; `belongsTo
User` (`assignedBy`).

---

## 4. `ticket_payment_links`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `ticket_id` | fk → `tickets` | no | unique — one link per ticket; a re-charged ticket after cancellation is a *new* ticket (Edge Cases); cascadeOnDelete |
| `amount` | decimal(15,2) | no | |
| `currency` | string(3) | no | |
| `status` | string(20) | no | cast to `PaymentLinkStatus`; default `pending` |
| `external_payment_reference` | string(255) | yes | **delta (FR-047)** — reserved, unused; replaces the ERD baseline's `stripe_payment_record_id` FK, which cannot be a real foreign key today because no `stripe_payment_records` table or model exists yet in this codebase (confirmed) |
| `payment_url` | string(1000) | yes | reserved, unused (FR-047) |
| `payment_method_reference` | string(255) | yes | **delta** — captured on settlement (FR-042) |
| `settled_by` | fk → `users` | yes | nullOnDelete |
| `settled_at` | timestamp | yes | |
| timestamps | | | |

**Relationships**: `belongsTo Ticket`.

**Validation**: settlement is idempotent — rejected once `status = settled`
(FR-044); the settlement write and the ticket's `pending_payment → live`
transition happen inside one transaction (FR-043).

---

## 5. `sla_policies` — new table (ADR 0004 extension 2)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `priority` | string(20) | no | unique; cast to `TicketPriority` — exactly 4 seeded rows; never created/deleted through the UI, only edited (FR-051) |
| `response_target_minutes` | unsigned int | no | seed defaults: Urgent 60, High 240, Normal 480, Low 1440 |
| `resolution_target_minutes` | unsigned int | no | seed defaults: Urgent 240, High 1440, Normal 2880, Low 4320 |
| `updated_by` | fk → `users` | yes | no `created_by` — rows are seeded, not user-created |
| timestamps | | | |

**Relationships**: none from `Ticket` — targets are copied onto the ticket at
clock-start (FR-053) and never joined live, so a later edit here never
changes an already-started ticket's due times (SC-006).

---

## 6. `maintenance_records` (business name: "Maintenance Request")

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `customer_id` | fk → `customer_profiles` | no | restrictOnDelete |
| `ticket_id` | fk → `tickets` | yes | nullOnDelete — set when raised from a ticket (FR-060), null when standalone (FR-061) |
| `product_variant_id` | fk → `product_variants` | yes | nullOnDelete |
| `serial_number` | string(255) | yes | free text; retained even when it matches no `serialized_inventory_units` row (FR-063) |
| `serialized_inventory_unit_id` | fk → `serialized_inventory_units` | yes | **delta (ADR 0004 ext. 3)** — nullOnDelete; set only when `serial_number` matches an existing unit |
| `warranty_status` | string(20) | no | **delta (ADR 0004 ext. 3)** — cast to `WarrantyStatus`; default `unknown` |
| `warranty_expiry_date` | date | yes | **delta (ADR 0004 ext. 3)** — required when `warranty_status = covered` (service-layer validation, FR-064) |
| `description` | text | no | |
| `status` | string(20) | no | cast to `MaintenanceStatus`; default `open` |
| `created_by` / `updated_by` | fk → `users` | yes | `TracksBlameable` |
| timestamps, `softDeletes` | | | archiving (FR-067) is a soft delete |

**Relationships**: `belongsTo CustomerProfile`; `belongsTo Ticket` (nullable);
`belongsTo ProductVariant` (nullable); `belongsTo SerializedInventoryUnit`
(nullable); `hasMany MaintenanceTask`.

---

## 7. `maintenance_tasks` (business name: "Service Record")

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `maintenance_record_id` | fk → `maintenance_records` | no | restrictOnDelete — never movable between parents (FR-071) |
| `employee_id` | fk → `employee_profiles` | yes | nullOnDelete |
| `title` | string(255) | no | |
| `description` | text | yes | |
| `due_at` | timestamp | yes | must not precede the parent's `created_at` (FR-072, service-layer validation) |
| `status` | string(20) | no | cast to `MaintenanceStatus` — the same vocabulary as `maintenance_records` (FR-073); default `open` |
| `created_by` / `updated_by` | fk → `users` | yes | `TracksBlameable` |
| timestamps, `softDeletes` | | | |

**Relationships**: `belongsTo MaintenanceRecord`; `belongsTo EmployeeProfile`
(nullable); `hasMany ServiceRecordPart`.

---

## 8. `service_record_parts` — new table (ADR 0004 extension 4)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `maintenance_task_id` | fk → `maintenance_tasks` | no | restrictOnDelete |
| `product_variant_id` | fk → `product_variants` | no | restrictOnDelete |
| `warehouse_id` | fk → `warehouses` | no | restrictOnDelete |
| `quantity` | decimal(15,3) | no | positive (FR-080); matches `stock_transfer_items.quantity` precision |
| `inventory_movement_id` | fk → `inventory_movements` | no | restrictOnDelete — the consumption movement (research.md §2) |
| `reversed_at` | timestamp | yes | full-quantity reversal only (clarification, 2026-08-13) |
| `reversed_by` | fk → `users` | yes | nullOnDelete |
| `reversal_movement_id` | fk → `inventory_movements` | yes | nullOnDelete — the compensating movement |
| `created_by` | fk → `users` | yes | `TracksBlameable` — no `updated_by`; FR-086 forbids editing |
| `created_at` | timestamp | no | no `updated_at` — immutable once written; only the `reversed_*` columns are ever set after creation, and only once |

**Relationships**: `belongsTo MaintenanceTask`; `belongsTo ProductVariant`;
`belongsTo Warehouse`; `belongsTo InventoryMovement` (`consumptionMovement`);
`belongsTo InventoryMovement` (`reversalMovement`, nullable).

**Validation**: rejected against a `maintenance_task` in a terminal status
(FR-085); reversal after the parent service record is closed requires the
System Admin role (FR-086); a reversal always compensates the full recorded
`quantity` — there is no partial-reversal path (clarification, 2026-08-13).

---

## Media collections

| Model | Collection | Disk | Notes |
|---|---|---|---|
| `Ticket` | `ticket-attachments` | `local` (private) | served via a new `TicketMediaController` + `Gate::authorize('view', $ticket)`, mirroring `VisitMediaController` (research.md §3) |

---

## Enum catalogue

| Enum | Cases | Transitions / reference |
|---|---|---|
| `TicketType` | `software_issue`, `hardware_issue`, `general_support`, `maintenance_request` | fixed catalogue (FR-011, Assumptions) |
| `TicketPriority` | `low`, `normal`, `high`, `urgent` | FR-050; drives `sla_policies` lookup |
| `TicketStatus` | `pending`, `pending_payment`, `live`, `assigned`, `in_progress`, `waiting_customer`, `resolved`, `closed`, `cancelled` | [contracts/ticket-lifecycle.md](./contracts/ticket-lifecycle.md) (FR-020–022) |
| `PaymentLinkStatus` | `pending`, `settled`, `cancelled` | FR-041/043/045 |
| `WarrantyStatus` | `covered`, `expired`, `unknown` | FR-064; `covered` requires `warranty_expiry_date` |
| `MaintenanceStatus` | `open`, `in_progress`, `closed`, `cancelled` | shared by `maintenance_records` and `maintenance_tasks` — [contracts/maintenance-lifecycle.md](./contracts/maintenance-lifecycle.md) (FR-065/073) |
| `SupportPermission` | dot-namespaced `support.{resource}.{verb}` catalogue | [contracts/permissions.md](./contracts/permissions.md) |
| `MovementType` *(existing, +1 case)* | adds `ServiceConsumption = 'service_consumption'` | research.md §2 |
| `DashboardRole` *(existing, +2 cases)* | adds `SupportManager = 'Support Manager'`, `SupportAgent = 'Support Agent'` | [contracts/permissions.md](./contracts/permissions.md) |

Every status enum implements `allowedTransitions(): array` and
`canTransitionTo(self): bool`, matching `SalesPlanStatus`/`PlanTaskStatus`/
`VisitStatus` (spec 015 precedent) — the domain service consults the enum, not
a hand-rolled `match` per service, so the same rule is enforced identically
whether invoked from Filament or a direct call.
