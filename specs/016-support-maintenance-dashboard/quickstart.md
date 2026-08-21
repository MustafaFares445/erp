# Quickstart: Validating the Support and Maintenance Dashboard

**Feature**: `specs/016-support-maintenance-dashboard` | **Date**: 2026-08-13

How to prove this feature works end to end. Scenarios map to the spec's nine
user stories and can be run independently, in story order — each later
scenario's manual walkthrough builds on records the earlier ones created.

---

## Prerequisites

```bash
php artisan migrate
```

```bash
php artisan db:seed --class=SupportPermissionSeeder
php artisan db:seed --class=SlaPolicySeeder
```

Local Xdebug is configured for coverage (`php.ini`,
`xdebug.mode = develop,debug,coverage`), so `composer test:coverage` works
without extra setup.

---

## Scenario 1 — Roles and permissions (US1, run first)

```bash
php artisan test --compact --filter=SupportPermission
php artisan test --compact --filter=CrossModulePermissionLeak
```

**Manual walkthrough**:

1. Sign in as `System Admin`; confirm every Support surface (Tickets,
   Maintenance Requests, Service Records, SLA policy, reports, audit) is
   reachable with every action available, including settlement and
   consumption reversal.
2. Sign in as `Support Manager`; confirm ticket create/triage/assign/close,
   maintenance-request and service-record management, and SLA policy editing
   work, and that payment settlement and consumption reversal are absent or
   403.
3. Sign in as `Support Agent`; confirm working a ticket *assigned to them*
   (message, transition, execute their service records, record parts) works,
   and that assigning to another agent, closing a ticket they don't own,
   editing SLA policy, and settling payment are all denied.
4. Sign in as `Reviewer`; confirm every list/report/audit view is visible and
   every write action is denied.
5. As `Support Manager` or `Support Agent`, try to open an Inventory,
   CRM, or Employees dashboard page directly by URL — confirm 403.
6. Via `artisan tinker`, call a Support domain service method directly
   (bypassing Filament entirely) as an unauthorized role — confirm it throws,
   proving the self-check in the service, not only the UI.

---

## Scenario 2 — Log and classify a ticket (US2)

```bash
php artisan test --compact --filter=TicketIntake
```

**Manual walkthrough** — Tickets → New:

1. Save with a customer, type, priority, title, and description; confirm a
   `TCK-######` number is assigned.
2. Attempt to save without a required field; confirm field-level rejection.
3. Attach a file; confirm it's retrievable from the ticket's view page.
4. Search by ticket number, then filter by status/type/priority/assignee.
5. Delete the ticket; confirm it disappears from the default list but is
   restorable from an Archived filter, with its number still reserved.

---

## Scenario 3 — Assignment and lifecycle (US3)

```bash
php artisan test --compact --filter=TicketLifecycle
```

**Manual walkthrough** — using the ticket from Scenario 2:

1. Triage `pending → live`, then assign to an employee (`live → assigned`).
2. As that employee, start work (`→ in_progress`), move to
   `waiting_customer`, then back, then `→ resolved → closed`.
3. Attempt a disallowed transition (e.g. `closed → in_progress`) — confirm
   rejection naming both statuses.
4. Reassign mid-flight; confirm the prior `TicketAssignment` row is retained
   and the ticket's current assignee reflects only the newest one.

---

## Scenario 4 — Paid tickets (US4)

```bash
php artisan test --compact --filter=TicketPayment
```

**Manual walkthrough**:

1. Create a chargeable ticket with an amount/currency; confirm status
   `pending_payment` and a pending `TicketPaymentLink`.
2. Attempt to assign it — confirm rejection.
3. As `System Admin`, record settlement; confirm the ticket moves to `live`
   and the link to `settled`.
4. Attempt a second settlement on the same link — confirm rejection, ticket
   status unchanged.
5. Cancel a different pending-payment ticket; confirm its payment link is
   cancelled in the same action.

---

## Scenario 5 — SLA tracking (US5)

```bash
php artisan test --compact --filter=Sla
```

**Manual walkthrough**:

1. Support Manager → SLA Policy: confirm the four seeded rows (Urgent 1h/4h,
   High 4h/24h, Normal 8h/48h, Low 24h/72h) and edit one.
2. Take a ticket to `live`; confirm `response_due_at`/`resolution_due_at` are
   set from the targets in force at that moment — editing the policy
   afterward must not change this ticket's due times.
3. Post the first agent message; confirm `first_response_at` is set once and
   a second message doesn't move it.
4. Move the ticket to `waiting_customer` for a few minutes, then back;
   confirm `resolution_due_at` extended by roughly the paused duration.
5. Raise the ticket's priority; confirm due times recompute from the
   original `live_at`, and that a resulting past-due recomputation flags a
   breach immediately.

---

## Scenario 6 — Maintenance requests (US6)

```bash
php artisan test --compact --filter=MaintenanceRequest
```

**Manual walkthrough**:

1. From an existing ticket (type `maintenance_request`), raise a maintenance
   request; confirm customer/description pre-filled and the ticket link
   visible both ways.
2. Create a standalone maintenance request with no ticket.
3. Enter a serial number matching a seeded `SerializedInventoryUnit`; confirm
   the equipment link and product variant display. Enter a non-matching
   serial number; confirm it saves as free text, flagged unlinked.
4. Set warranty `covered` without an expiry date; confirm rejection. Set it
   with a date; confirm it saves.
5. Move `open → in_progress → closed`; attempt `closed → in_progress`,
   confirm rejection.

---

## Scenario 7 — Service records (US7)

```bash
php artisan test --compact --filter=ServiceRecord
```

**Manual walkthrough** — under the maintenance request from Scenario 6:

1. Add a service record with a title, assignee, and due date on or after the
   parent's creation date; attempt an earlier due date, confirm rejection.
2. As a different agent than the assignee, attempt to execute it — confirm
   denial; as the assignee, move `open → in_progress` and confirm the parent
   maintenance request cascades to `in_progress`.
3. Close the service record; confirm overdue/due-soon/closed styling in the
   list reflects the change.
4. Attempt to close the parent maintenance request while another of its
   service records is still open — confirm rejection.

---

## Scenario 8 — Spare-parts consumption (US8)

```bash
php artisan test --compact --filter=ServiceRecordPart
```

**Manual walkthrough** — under the service record from Scenario 7 (reopen it
to `in_progress` first):

1. Record a consumption (variant, warehouse, positive quantity); confirm
   stock decreases and exactly one `InventoryMovement` references the part.
2. Attempt a consumption exceeding available stock; confirm rejection naming
   the available quantity, no stock change.
3. As `System Admin`, reverse the first consumption; confirm a compensating
   movement restores stock and the original record is untouched apart from
   its `reversed_*` columns.
4. As `Support Manager`, attempt the same reversal; confirm denial.
5. Close the service record, then attempt a new consumption against it;
   confirm rejection.

---

## Scenario 9 — Search, reports, and audit (US9)

```bash
php artisan test --compact --filter=SupportReport
```

**Manual walkthrough**:

1. Search/filter the ticket, maintenance-request, and service-record lists
   using data from Scenarios 2–8.
2. Open the workload report; confirm open-ticket counts by status, priority,
   and assignee match what was created above.
3. Open the SLA report; confirm breach counts and average resolution time for
   the current period.
4. Open the maintenance report; confirm open requests, overdue service
   records, and parts consumed per period.
5. Open the audit view for the ticket from Scenario 4; confirm the
   settlement action is retrievable with actor and timestamp.

---

## Full regression

```bash
composer test
```

Must stay green with no PHPStan baseline growth and no drop below 100% type/
code coverage (SC-014), matching every prior feature in this codebase.
