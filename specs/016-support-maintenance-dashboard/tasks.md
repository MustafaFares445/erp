# Tasks: Support and Maintenance Dashboard

**Input**: Design documents from `specs/016-support-maintenance-dashboard/`
(spec.md, plan.md, research.md, data-model.md, contracts/, quickstart.md)

**Tests**: Included in every phase — CLAUDE.md's AI Feature Development
Standard ("Test every behavior change") and Constitution Principle VI
("Add tests for every implemented business rule") make this project's
tests non-optional, matching the precedent set by every prior feature's
`tasks.md` in this repo.

**Organization**: Tasks are grouped by user story (spec.md US1–US9) so each
can be implemented and tested independently. User Story 1 (roles/permissions)
has no entity of its own — it is satisfied by Phase 2 (Foundational) plus a
policy/direct-service-call test embedded in every later story's own phase,
matching how `specs/015-employees-plans-visits-dashboard/tasks.md` treated
its own roles-and-permissions story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Maps the task to spec.md's user stories (US2–US9)
- Every task names an exact file path

## Path Conventions

Laravel modular monolith (plan.md §Project Structure): `app/Models/`,
`app/Enums/`, `app/Services/Support/`, `app/Policies/`,
`app/Filament/Resources/<Plural>/`, `database/migrations/`,
`database/seeders/`, `database/factories/`, `tests/Feature/Support/`,
`tests/Unit/Enums/`.

---

## Phase 1: Setup

**Purpose**: Wire the already-pinned nav group to its two not-yet-pinned
resources and record the pre-feature green baseline.

- [~] T001 **Revised during implementation**: adding `SlaPolicyResource`/
      `SupportReportResource` to `AdminModuleRegistry.php` before those
      classes exist made PHPStan's `class.notFound` baseline grow, which
      CLAUDE.md forbids ("baseline may only shrink"). Deferred: each
      resource is now wired into `AdminModuleRegistry.php` in its own story
      (T0xx of US5 for `SlaPolicyResource`, T0xx of US9 for
      `SupportReportResource`), matching how `TicketResource`/
      `MaintenanceRequestResource`/`ServiceRecordResource` were already
      pre-pinned only once real classes existed for them.
- [X] T002 [P] Add `admin.resources.sla_policies` and
      `admin.resources.support_reports` keys to `lang/en/admin.php` (every
      other Support label already exists there)
- [X] T003 Run `composer test` and record the passing suite list as the
      green baseline — command-only, no file changes

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Cross-cutting RBAC infrastructure every user story depends on.
Satisfies User Story 1 (Enforce Dashboard Roles and Permissions) in full,
mirroring how `EmployeePermission`/`ChecksEmployeePermissions` were built in
spec 015's own Foundational phase.

**⚠️ CRITICAL**: No user story implementation may begin until this phase is
complete.

- [X] T004 Create `app/Enums/SupportPermission.php` with the 17-permission
      catalogue from [contracts/permissions.md](./contracts/permissions.md)
      (no `fixedRoleNames()` method — only `DashboardRole::fixedRoleNames()`
      is ever consulted)
- [X] T005 Modify `app/Enums/DashboardRole.php` — add
      `SupportManager = 'Support Manager'` and
      `SupportAgent = 'Support Agent'` cases
- [X] T006 Modify `app/Enums/MovementType.php` — add
      `ServiceConsumption = 'service_consumption'` case
- [X] T007 Create `app/Policies/Concerns/ChecksSupportPermissions.php`
      mirroring `ChecksEmployeePermissions` (the `isAdmin() &&
      !hasAnyRole(DashboardRole::fixedRoleNames())` bypass, `forceDelete()`
      hardcoded false)
- [X] T008 Create `database/seeders/SupportPermissionSeeder.php` (idempotent,
      mirrors `EmployeePermissionSeeder`; grants the exact role matrix from
      contracts/permissions.md, including `System Admin` explicitly getting
      every permission)
- [X] T009 [P] Create `tests/Feature/Support/CrossModulePermissionLeakTest.php`
      — a user holding only `Support Manager` (and, separately, only
      `Support Agent`) is denied against `EmployeeProfilePolicy`,
      `CustomerProfilePolicy`, and a Warehouse/Inventory policy
- [X] T010 Extend `tests/Feature/Employees/CrossModulePermissionLeakTest.php`
      and its CRM/Inventory equivalents with the reverse case: a user
      holding only `Support Manager` or `Support Agent` is denied against
      those modules' own policies (ADR 0004 consequence; FR-009)
- [X] T011 [P] Extend `tests/Unit/ArchTest.php`: ban any class under
      `App\Filament\Resources\{Tickets,MaintenanceRequests,ServiceRecords,
      SlaPolicies,SupportReports}` from referencing `InventoryStock` or
      `InventoryMovement` directly (must go through
      `InventoryBalanceService`/the Support services)
- [X] T012 [P] Extend `tests/Unit/ArchTest.php`: ban any class under
      `App\Services\Support` from calling `auth()->user()` internally
      (every service takes an explicit `User $actor` parameter)
- [X] T013 [P] Feature test: `SupportPermissionSeederTest` — catalogue
      count/names; running the seeder twice yields no duplicates; a role
      granted a subset causes `can()` to reflect exactly that subset
- [X] T014 Run `php artisan test --compact --filter=Support` and
      `--filter=CrossModulePermissionLeak`, confirm no regressions —
      command-only

**Checkpoint**: RBAC foundation ready — User Story 1's guarantee holds
end-to-end even before any Support entity exists. User story implementation
can now begin.

---

## Phase 3: User Story 2 - Log and Classify a Support Ticket (Priority: P1) 🎯 MVP

**Goal**: A Support Manager or Support Agent logs a ticket with type,
priority, description, and files; the system issues a unique ticket number.

**Independent Test**: Create, edit, search, filter, and cancel a ticket
without assigning it, without payment, and without any maintenance record.

### Tests for User Story 2

> Write these tests FIRST; confirm they fail before implementation.

- [X] T015 [P] [US2] Feature test: `ticket_number` uniqueness is checked
      `withTrashed()` on generation, in `tests/Feature/Support/TicketIntakeTest.php`
- [X] T016 [P] [US2] Feature test: saving without customer/type/priority/
      title/description is rejected with field-level messages
- [X] T017 [P] [US2] Feature test: concurrent ticket creation never produces
      a duplicate `ticket_number` (SC-001)
- [X] T018 [P] [US2] Feature test: `is_chargeable = true` sets status
      `pending_payment` + `pending_reason`; `false` sets `pending` (FR-021).
      **Revised**: the "creates a pending `TicketPaymentLink`" half of this
      assertion moved to US4's T060 — `TicketPaymentLink` doesn't exist until
      that story, matching US2's own Independent Test ("without... payment").
- [X] T019 [P] [US2] Feature test: a non-permitted attachment file type is
      rejected before any record is written
- [X] T020 [P] [US2] Feature test: search by number/customer/title and
      filter by status/type/priority/assignee/SLA-breach, with pagination
- [X] T021 [P] [US2] Feature test: deleting a ticket archives (soft-delete)
      rather than removes it; its number stays reserved
- [X] T022 [P] [US2] Policy test: `TicketPolicy` viewAny/create/update/delete
      — page-open, direct-action, bulk-action, and direct-service-call
      parity, in `tests/Feature/Support/TicketPolicyTest.php`

### Implementation for User Story 2

- [X] T023 [US2] Create the `tickets` migration per
      [data-model.md](./data-model.md) §1 — the full column set, including
      the priority/SLA/continuation fields US4/US5 will populate later
- [X] T024 [P] [US2] Create `app/Enums/TicketType.php`
- [X] T025 [P] [US2] Create `app/Enums/TicketPriority.php`
- [X] T026 [P] [US2] Create `app/Enums/TicketStatus.php` with
      `allowedTransitions()`/`canTransitionTo()` per
      [contracts/ticket-lifecycle.md](./contracts/ticket-lifecycle.md) §1
- [X] T027 [US2] Create `app/Models/Ticket.php` — `casts()`,
      `TracksBlameable`, `HasMedia`/`InteractsWithMedia` with the
      `ticket-attachments` collection, relations (customer, assignedEmployee,
      continuedFromTicket — `messages`/`assignments`/`paymentLink`/
      `maintenanceRecords` added in US3/US4/US6 once those models exist, to
      avoid a PHPStan `class.notFound` error on a forward reference). Also
      added the missing symmetric `User::employeeProfile()` `HasOne` relation
      (mirrors the existing `customerProfile()`) — needed by `TicketPolicy`'s
      ownership checks and absent from the codebase until now.
- [X] T028 [P] [US2] Create `database/factories/TicketFactory.php` with
      states (`chargeable()`, `withPriority()`)
- [X] T029 [US2] Create `app/Policies/TicketPolicy.php` using
      `ChecksSupportPermissions`
- [X] T030 [US2] Create `app/Services/Support/TicketIntakeService.php` —
      `nextTicketNumber()` (locking-read + unique-constraint backstop,
      research.md §1), `create()`/`update()` with the chargeable branch per
      [contracts/ticket-lifecycle.md](./contracts/ticket-lifecycle.md) §2
- [X] T031 [US2] Create
      `app/Services/Support/TicketAttachmentSynchronizer.php` mirroring
      `ProductMediaSynchronizer`
- [X] T032 [US2] Create `app/Http/Controllers/TicketMediaController.php` —
      signed route, `Gate::authorize('view', $ticket)`, mirrors
      `VisitMediaController`; registered in `routes/web.php`
- [X] T033 [US2] Create
      `app/Filament/Resources/Tickets/TicketResource.php` (class already
      pinned in `AdminModuleRegistry`)
- [X] T034 [P] [US2] Create `Tickets/Schemas/TicketForm.php` (customer,
      type, priority, title, description, attachment upload — chargeable
      amount/currency fields deferred to US4 alongside `TicketPaymentLink`)
- [X] T035 [P] [US2] Create `Tickets/Schemas/TicketInfolist.php`
- [X] T036 [P] [US2] Create `Tickets/Tables/TicketsTable.php` with
      search/filter/pagination and an archive `Action` (`->authorize('delete')`)
- [X] T037 [US2] Create
      `Tickets/Pages/{ListTickets,CreateTicket,ViewTicket,EditTicket}.php`
- [X] T038 [US2] **Revised**: Filament's native `->required()` messages
      (verified by T016's Livewire test) covered every US2 validation need;
      no bespoke `lang/en/admin.php` keys were needed beyond the
      already-present resource/group labels.
- [X] T039 [US2] Run `php artisan test --compact --filter=TicketIntake` and
      confirm all US2 tests pass — command-only

**Checkpoint**: User Story 2 is fully functional and independently testable.

---

## Phase 4: User Story 3 - Assign and Work a Ticket Through Its Lifecycle (Priority: P1)

**Goal**: A Support Manager assigns a ticket; the assigned Support Agent
moves it through its documented lifecycle to closure, every assignment and
transition recorded.

**Independent Test**: Take a ticket from `pending` to `closed` through
assignment, work, a customer wait, resolution, and closure, plus attempt
every disallowed transition, without touching payment, maintenance, or parts.

### Tests for User Story 3

- [X] T040 [P] [US3] Feature test: every FR-022 allowed transition succeeds;
      every disallowed pair is rejected naming current/attempted status,
      including a direct service call bypassing the UI, in
      `tests/Feature/Support/TicketLifecycleTest.php`
- [X] T041 [P] [US3] Feature test: assigning appends a `TicketAssignment` row
      and sets the current assignee; reassignment appends a new row,
      retains the prior one, and the current assignee reflects only the
      newest
- [~] T042 [P] [US3] **Deferred to US6**: closing a ticket with a
      non-terminal linked maintenance request (FR-026) can't be tested or
      enforced until `MaintenanceRecord` exists — moved to US6's own task
      list alongside `MaintenanceRecordService`.
- [X] T043 [P] [US3] Feature test: posting the first customer-visible
      message sets `first_response_at` once; an internal note never sets
      it; a later message never overwrites it
- [X] T044 [P] [US3] Feature test: a Support Agent can post/transition only
      on a ticket assigned to them; denied on another agent's ticket
- [X] T045 [P] [US3] Enum unit test: every `TicketStatus` allowed and
      rejected transition pair, in `tests/Unit/Enums/TicketStatusTest.php`
- [X] T046 [P] [US3] Policy test: covered inline by T040/T044's
      `AuthorizationException` assertions rather than a separate file —
      `work`/`message`/`assign` all self-check via `Gate::forUser()` inside
      the service, so the direct-service-call path *is* the policy test.

### Implementation for User Story 3

- [X] T047 [US3] Create the `ticket_assignments` migration per
      [data-model.md](./data-model.md) §3
- [X] T048 [US3] Create the `ticket_messages` migration per
      [data-model.md](./data-model.md) §2
- [X] T049 [US3] Create `app/Models/TicketAssignment.php` with a model-level
      guard rejecting any update (append-only)
- [X] T050 [US3] Create `app/Models/TicketMessage.php` with the same
      append-only guard, `is_internal_note` cast
- [X] T051 [P] [US3] Create `database/factories/TicketAssignmentFactory.php`
- [X] T052 [P] [US3] Create `database/factories/TicketMessageFactory.php`
      with states (`internalNote()`, `customerVisible()`)
- [X] T053 [US3] Create `app/Services/Support/TicketLifecycleService.php` —
      `transition()`/`assign()`/`unassign()` per
      [contracts/ticket-lifecycle.md](./contracts/ticket-lifecycle.md) §1/§3,
      self-checking authorization. Also added
      `app/Services/Support/Exceptions/InvalidStatusTransition.php`
      (mirrors the Employees module's own exception, per module — Principle
      II) and a `support.errors.invalid_status_transition` lang key.
- [X] T054 [US3] Create `app/Services/Support/TicketMessageService.php` —
      `post()` per §4
- [X] T055 [P] [US3] Create
      `Tickets/RelationManagers/AssignmentsRelationManager.php`
- [X] T056 [P] [US3] Create
      `Tickets/RelationManagers/MessagesRelationManager.php` with the
      internal-note toggle
- [X] T057 [US3] Wire transition/assign/unassign `Action`s into
      `Tickets/Tables/TicketsTable.php`, each with `->authorize(...)` or an
      internal `Gate::forUser()` check inside the service
- [X] T058 [US3] Added the `support.errors.invalid_status_transition` lang
      key (see T053) — no further assignment-specific keys were needed.
- [X] T059 [US3] Run `php artisan test --compact --filter=TicketLifecycle`
      and confirm all US3 tests pass — command-only

**Checkpoint**: User Stories 2 AND 3 both work independently.

---

## Phase 5: User Story 4 - Hold a Paid Ticket Until Payment Is Recorded (Priority: P1)

**Goal**: A chargeable ticket is held at `pending_payment` with a payment
link and becomes workable only after an authorized user (System Admin)
records settlement.

**Independent Test**: Create a chargeable ticket, confirm it cannot be
assigned or worked, record settlement, confirm it becomes workable, and
attempt a duplicate settlement — all without maintenance or parts.

### Tests for User Story 4

- [X] T060 [P] [US4] Feature test: a chargeable ticket's saved
      `TicketPaymentLink` carries the amount/currency/pending status, in
      `tests/Feature/Support/TicketPaymentTest.php`
- [X] T061 [P] [US4] Feature test: assignment or any work transition on a
      `pending_payment` ticket is rejected. **Revealed a gap**: US3's
      `TicketLifecycleService::transition()` structurally allowed a
      Support-Manager-initiated `pending_payment -> live` call (the enum
      permits it), contradicting this contract's "`live`: system, via
      `TicketPaymentService::settle()` only (FR-043)". Fixed as part of this
      task: `transition()` now explicitly rejects `pending_payment -> live`
      so that edge is settlement-only, matching the contract exactly.
- [X] T062 [P] [US4] Feature test: settlement moves the link to `settled`
      and the ticket to `live`, clears `pending_reason`, in one transaction
- [X] T063 [P] [US4] Feature test: settling an already-settled link is
      rejected, ticket status unchanged (FR-044); concurrent settlement of
      the same link yields exactly one `settled` row (SC-003)
- [X] T064 [P] [US4] Feature test: cancelling a `pending_payment` ticket
      cancels its payment link in the same transaction
- [X] T065 [P] [US4] Feature test: settling a ticket cancelled between
      page-load and submit is rejected, cancellation intact
- [X] T066 [P] [US4] Regression test: a recorded settlement produces zero
      rows in any accounting-adjacent table (SC-004)
- [X] T067 [P] [US4] Policy test: `TicketPolicy` `settle-payment` ability is
      System-Admin-only — page-open, direct-action, direct-service-call
      parity. `TicketPolicy::settlePayment()` and its permission-map entry
      already existed from US2/US3 scaffolding — only the test was new.

### Implementation for User Story 4

- [X] T068 [US4] Create the `ticket_payment_links` migration per
      [data-model.md](./data-model.md) §4
- [X] T069 [P] [US4] Create `app/Enums/PaymentLinkStatus.php`
- [X] T070 [US4] Create `app/Models/TicketPaymentLink.php` — also added
      `Ticket::paymentLink(): HasOne`, deferred from US2 per T027's own note.
- [X] T071 [P] [US4] Create
      `database/factories/TicketPaymentLinkFactory.php` with states
      (`settled()`, `cancelled()`)
- [X] T072 [US4] Create `app/Services/Support/TicketPaymentService.php` —
      `settle()` per
      [contracts/ticket-lifecycle.md](./contracts/ticket-lifecycle.md) §5,
      wired into `TicketIntakeService`'s chargeable branch (T030) and
      `TicketLifecycleService`'s cancel path (T053). Also added the
      `amount`/`currency`/chargeable-toggle fields to `TicketForm.php`,
      deferred from US2 per T034's own note.
- [X] T073 [US4] Add a System-Admin-only "Settle Payment" `Action` to
      `Tickets/Tables/TicketsTable.php` (modal collecting a payment
      reference); the `ViewTicket` page reuses the resource's table action
      via its record page, so no separate header action was needed there.
- [X] T074 [US4] **Revised**: as with T038, every US4 UI string is an inline
      Filament label (`'Settle Payment'`, `'Payment reference'`, etc.),
      matching `TicketForm`/`TicketsTable`'s existing convention — no
      bespoke `lang/en/admin.php` keys were needed.
- [X] T075 [US4] Run `php artisan test --compact --filter=TicketPayment`
      and confirm all US4 tests pass — command-only. Also re-ran
      `--filter=Support`, `--filter=CrossModulePermissionLeak`, and
      `tests/Unit/ArchTest.php` (43/43, 6/6, 9/9) to confirm no regression
      from the `TicketLifecycleService` fix above, then `phpstan analyse`
      (0 errors) and `pint --dirty` (clean).

**Checkpoint**: User Stories 2, 3, AND 4 all work independently.

---

## Phase 6: User Story 5 - Track Response and Resolution Against SLA (Priority: P2)

**Goal**: SLA targets per priority; every workable ticket carries response/
resolution due times; breaches are visible.

**Independent Test**: Configure targets, take one ticket to breach and one
to compliance, pause one in `waiting_customer`, raise one ticket's priority,
verifying due times and flags at each step.

### Tests for User Story 5

- [X] T076 [P] [US5] Feature test: the SLA clock starts only at `live`,
      snapshotting the priority's current targets onto the ticket, in
      `tests/Feature/Support/SlaTest.php`
- [X] T077 [P] [US5] Feature test: a `pending_payment` ticket accrues no SLA
      time before settlement
- [X] T078 [P] [US5] Feature test: response/resolution breach flags are set
      when due times pass without the corresponding event, and stay set
      through a later priority change, reopen, or policy edit (SC-006/SC-007)
- [X] T079 [P] [US5] Feature test: a `waiting_customer` pause suspends the
      resolution clock; resuming extends `resolution_due_at` by the paused
      duration rather than consuming it
- [X] T080 [P] [US5] Feature test: a priority change recomputes due times
      from the original `live_at` using the new priority's targets, audits
      the change, and flags breach immediately if already past due
- [X] T081 [P] [US5] Feature test: editing `SlaPolicy` after a ticket's
      clock has started never changes that ticket's due times (SC-006)
- [X] T082 [P] [US5] Feature test: `SlaPolicySeeder` produces exactly the
      four documented default rows and is idempotent
- [X] T083 [P] [US5] Policy test: `SlaPolicyPolicy` — Support Manager can
      edit; Support Agent and Reviewer cannot. **Also revised**: US3/US4's
      `TicketLifecycleTest.php`/`TicketPaymentTest.php` now seed
      `SlaPolicySeeder` too, since any ticket reaching `live` invokes
      `SlaService::onTicketLive()`, which throws `ModelNotFoundException`
      without a seeded policy row for the ticket's priority — production
      always has all 4 rows seeded, so this matches real behavior rather
      than masking it.

### Implementation for User Story 5

- [X] T084 [US5] Create the `sla_policies` migration per
      [data-model.md](./data-model.md) §5
- [X] T085 [US5] Create `database/seeders/SlaPolicySeeder.php` — 4 rows,
      defaults per research.md §7, idempotent (`updateOrCreate` on
      `priority`)
- [X] T086 [US5] Create `app/Models/SlaPolicy.php`
- [X] T087 [P] [US5] Create `database/factories/SlaPolicyFactory.php`
- [X] T088 [US5] Create `app/Policies/SlaPolicyPolicy.php` using
      `ChecksSupportPermissions`
- [X] T089 [US5] Create `app/Services/Support/SlaService.php` —
      `onTicketLive()`/`onWaitingCustomer()`/`onResumeFromWaiting()`/
      `onPriorityChanged()` per
      [contracts/ticket-lifecycle.md](./contracts/ticket-lifecycle.md) §6.
      Breach-flag computation is `refreshBreachFlags()`, swept by a new
      `support:sla:reconcile` Artisan command
      (`app/Console/Commands/ReconcileSlaBreachesCommand.php`, scheduled
      `->everyFiveMinutes()` in `routes/console.php`) — the "scheduled" half
      of the contract's "a scheduled/on-read check", mirroring the existing
      `inventory:alerts:reconcile` precedent.
- [X] T090 [US5] Wire `SlaService` hooks into
      `TicketLifecycleService::transition()` (live/waiting_customer/resume)
      and into `TicketIntakeService::update()` (priority change, only when
      `live_at` is already set) and `TicketPaymentService::settle()` (T072)
      — settlement itself lands a ticket on `live` without going through
      `transition()`, so it needed its own `onTicketLive()` call too.
- [X] T091 [US5] Create
      `app/Filament/Resources/SlaPolicies/SlaPolicyResource.php` (List +
      Edit only — 4 fixed rows, no Create/Delete, mirroring
      `DashboardUserResource`'s `canCreate()`/`canDeleteAny()` pattern) and
      wired it into `AdminModuleRegistry.php`'s `support` group (deferred
      from Setup per T001's own note — the class now exists).
- [X] T092 [P] [US5] Create `SlaPolicies/Schemas/SlaPolicyForm.php`,
      `SlaPolicies/Tables/SlaPoliciesTable.php`,
      `SlaPolicies/Pages/{ListSlaPolicies,EditSlaPolicy}.php`. `updated_by`
      is stamped by a model-level `booted()`/`updating()` hook on
      `SlaPolicy` itself (data-model.md §5 has no `created_by`, so
      `TracksBlameable` — which assumes both columns — wasn't reused);
      `tests/Unit/ArchTest.php`'s strict-preset exemption list gained
      `SlaPolicy::class` for this required Eloquent-override shape, same
      reasoning as `TicketAssignment`/`TicketMessage`.
- [X] T093 [US5] **Revised**: the response/resolution breach `IconColumn`s
      already existed in `Tickets/Tables/TicketsTable.php` since US2 (dormant
      until this story populated the underlying columns) — no further visual
      distinction or lang keys were needed beyond what's already inline.
- [X] T094 [US5] Run `php artisan test --compact --filter=Sla` (20/20) and
      confirm all US5 tests pass — command-only. Also re-ran
      `--filter=Support` (52/52), `--filter=CrossModulePermissionLeak`
      (6/6), and `tests/Unit/ArchTest.php` (9/9), then `phpstan analyse`
      (0 errors) and `pint --dirty` (clean).

**Checkpoint**: User Stories 2–5 all work independently.

---

## Phase 7: User Story 6 - Raise and Track a Maintenance Request (Priority: P2)

**Goal**: Raise a maintenance request from a ticket or standalone, with
equipment/warranty data.

**Independent Test**: Raise one from a ticket and one standalone, link
equipment and warranty, move through statuses, cancel one — without any
service record or parts.

### Tests for User Story 6

- [X] T095 [P] [US6] Feature test: raising from a ticket pre-fills customer/
      description and links both ways, in
      `tests/Feature/Support/MaintenanceRequestTest.php`
- [X] T096 [P] [US6] Feature test: standalone creation requires
      customer+description, no `ticket_id`
- [X] T097 [P] [US6] Feature test: a matching serial number links the
      `SerializedInventoryUnit` and shows its product variant; a
      non-matching one saves as free text, flagged unlinked
- [X] T098 [P] [US6] Feature test: `warranty_status = covered` without
      `warranty_expiry_date` is rejected at save
- [X] T099 [P] [US6] Feature test: only `open→in_progress|cancelled` and
      `in_progress→closed|cancelled` are permitted; `closed`/`cancelled`
      terminal
- [X] T100 [P] [US6] Feature test: deleting archives (soft-delete).
      **Revised**: "its service records and parts remain intact" can't be
      exercised until `MaintenanceTask` exists (US7) — the archive-behavior
      half is tested now; the service-records-survive half moves to US7.
- [X] T101 [P] [US6] Feature test: a disposed/adjusted-out
      `SerializedInventoryUnit`'s link and `serial_number` survive on the
      maintenance record (FR-068)
- [X] T102 [P] [US6] Enum unit test: every `MaintenanceStatus` allowed and
      rejected transition pair, in `tests/Unit/Enums/MaintenanceStatusTest.php`
- [X] T103 [P] [US6] Policy test: `MaintenanceRecordPolicy` — page-open,
      direct-action, bulk-action, direct-service-call parity. Also added the
      deferred T042 (FR-026: closing a ticket with a non-terminal linked
      maintenance request is rejected, even directly) to
      `tests/Feature/Support/TicketLifecycleTest.php`, now that
      `MaintenanceRecord` exists.

### Implementation for User Story 6

- [X] T104 [US6] Create the `maintenance_records` migration per
      [data-model.md](./data-model.md) §6 — full column set including
      warranty/serialized-unit fields
- [X] T105 [P] [US6] Create `app/Enums/MaintenanceStatus.php` with
      `allowedTransitions()`/`canTransitionTo()` per
      [contracts/maintenance-lifecycle.md](./contracts/maintenance-lifecycle.md) §1
- [X] T106 [P] [US6] Create `app/Enums/WarrantyStatus.php`
- [X] T107 [US6] Create `app/Models/MaintenanceRecord.php` — `casts()`,
      `TracksBlameable`, relations (customer, ticket, productVariant,
      serializedInventoryUnit). Also added `Ticket::maintenanceRecords()`
      (deferred from US2 per T027's own note — the class now exists) and,
      per that same forward-reference discipline, deferred
      `serviceRecords()` to US7 once `MaintenanceTask` exists.
- [X] T108 [P] [US6] Create
      `database/factories/MaintenanceRecordFactory.php` with states
      (`fromTicket()`, `standalone()`, `covered()`)
- [X] T109 [US6] Create `app/Policies/MaintenanceRecordPolicy.php` using
      `ChecksSupportPermissions`
- [X] T110 [US6] Create `app/Services/Support/MaintenanceRecordService.php`
      — `createFromTicket()`/`createStandalone()`/`transition()` per
      [contracts/maintenance-lifecycle.md](./contracts/maintenance-lifecycle.md) §2.
      **Revised**: FR-066's "reject closing while a non-terminal service
      record exists" guard is deferred to US7 for the same forward-reference
      reason as `serviceRecords()` above — `MaintenanceTask` doesn't exist
      yet. Also added a plain `update()` method (descriptive-field/warranty/
      equipment corrections, logging `support.maintenance_record.updated`,
      extending the audit-log.md catalogue the same way `TicketIntakeService::update()`
      already established `support.ticket.updated`) since T115's
      `EditMaintenanceRequest` page needs a save path beyond creation.
      Also added `TicketLifecycleService`'s FR-026 guard (rejecting
      `-> closed` while a linked `MaintenanceRecord` is non-terminal),
      deferred from US3's T042.
- [X] T111 [US6] Create
      `app/Filament/Resources/MaintenanceRequests/MaintenanceRequestResource.php`
      (class already pinned) — wired into `AdminModuleRegistry.php`'s
      `support` group and its `phpstan-baseline.neon` `class.notFound` entry
      removed now that the class exists (Principle: baseline may only
      shrink).
- [X] T112 [P] [US6] Create
      `MaintenanceRequests/Schemas/MaintenanceRequestForm.php`
      (serial-number lookup + equipment/warranty fields)
- [X] T113 [P] [US6] Create
      `MaintenanceRequests/Schemas/MaintenanceRequestInfolist.php`
- [X] T114 [P] [US6] Create
      `MaintenanceRequests/Tables/MaintenanceRequestsTable.php`
- [X] T115 [US6] Create
      `MaintenanceRequests/Pages/{ListMaintenanceRequests,
      CreateMaintenanceRequest,ViewMaintenanceRequest,
      EditMaintenanceRequest}.php`
- [X] T116 [US6] Add a "Raise Maintenance Request" `Action` to
      `Tickets/Tables/TicketsTable.php`/`ViewTicket` pre-filling from the
      ticket (via a `?ticket_id=` query parameter read by
      `CreateMaintenanceRequest::mount()`)
- [X] T117 [US6] **Revised**: as with T038/T074, every US6 UI string is an
      inline Filament label — no bespoke `lang/en/admin.php` keys were
      needed beyond the already-present `admin.resources.maintenance_requests`.
- [X] T118 [US6] Run `php artisan test --compact --filter=MaintenanceRequest`
      (8/8) and confirm all US6 tests pass — command-only. Also re-ran
      `--filter=Support` (62/62), `--filter=CrossModulePermissionLeak` (6/6),
      `tests/Unit/ArchTest.php` (9/9), `phpstan analyse` (0 errors),
      `pint --dirty` (clean), and the full suite via
      `php artisan test --compact --parallel` (1262/1262) to confirm no
      regression anywhere else in the codebase.

**Checkpoint**: User Stories 2–6 all work independently.

---

## Phase 8: User Story 7 - Plan and Execute Service Records (Priority: P2)

**Goal**: Plan service records under a maintenance request; the assigned
employee executes and closes each one.

**Independent Test**: Create, assign, execute, and close service records;
attempt an unauthorized execution and a disallowed transition — without
consuming parts.

### Tests for User Story 7

- [X] T119 [P] [US7] Feature test: title required, belongs to exactly one
      maintenance request, never movable between parents, in
      `tests/Feature/Support/ServiceRecordTest.php`
- [X] T120 [P] [US7] Feature test: `due_at` before the parent's `created_at`
      is rejected
- [X] T121 [P] [US7] Feature test: only `open→in_progress|cancelled` and
      `in_progress→closed|cancelled` permitted, matching `MaintenanceStatus`
- [X] T122 [P] [US7] Feature test: an agent executing another employee's
      service record is denied; their own succeeds
- [X] T123 [P] [US7] Feature test: the first service record reaching
      `in_progress` cascades its `open` parent to `in_progress` in the same
      transaction; a second one does not re-trigger the cascade (asserted via
      exactly one `support.maintenance_record.status_changed` audit row)
- [X] T124 [P] [US7] Feature test: overdue/due-soon/closed are visually
      distinguished in the list
- [X] T125 [P] [US7] Feature test: every status change is audited with
      actor, timestamp, optional note
- [X] T126 [P] [US7] Policy test: `MaintenanceTaskPolicy` manage/execute
      abilities — page-open, direct-action, bulk-action, direct-service-call
      parity. Also added the deferred FR-066 guard (closing a maintenance
      request with a non-terminal service record is rejected, even directly)
      to `tests/Feature/Support/MaintenanceRequestTest.php`, now that
      `MaintenanceTask` exists — mirroring how T042's FR-026 equivalent was
      deferred from US3 into US6.

### Implementation for User Story 7

- [X] T127 [US7] Create the `maintenance_tasks` migration per
      [data-model.md](./data-model.md) §7
- [X] T128 [US7] Create `app/Models/MaintenanceTask.php` — `casts()`,
      `TracksBlameable`, relations (`maintenanceRecord`, `employee`). Also
      added `MaintenanceRecord::serviceRecords()` (deferred from US6 per
      T107's own note — the class now exists); `parts()` stays deferred to
      US8 for the same forward-reference reason.
- [X] T129 [P] [US7] Create `database/factories/MaintenanceTaskFactory.php`
      with states (`overdue()`, `dueSoon()`, `closed()`)
- [X] T130 [US7] Create `app/Policies/MaintenanceTaskPolicy.php` using
      `ChecksSupportPermissions` with the assignee-ownership check
      (`execute` ability)
- [X] T131 [US7] Create `app/Services/Support/ServiceRecordService.php` —
      `create()`/`transition()` with the parent-cascade rule per
      [contracts/maintenance-lifecycle.md](./contracts/maintenance-lifecycle.md) §3.
      Also added a plain `update()` method (title/description/due-date/
      assignee corrections, logging `support.service_record.updated`, same
      reasoning as `MaintenanceRecordService::update()` in US6) for
      `EditServiceRecord`'s save path, and added
      `MaintenanceRecordService::transition()`'s deferred FR-066 guard
      (rejecting `-> closed` while a service record is non-terminal) now
      that `MaintenanceTask::serviceRecords` exists.
- [X] T132 [US7] Create
      `app/Filament/Resources/ServiceRecords/ServiceRecordResource.php`
      (class already pinned; standalone list/view for cross-request search)
      — wired into `AdminModuleRegistry.php`'s `support` group (already
      present from Setup) and its `phpstan-baseline.neon` `class.notFound`
      entry removed now that the class exists.
- [X] T133 [P] [US7] Create
      `MaintenanceRequests/RelationManagers/ServiceRecordsRelationManager.php`
      — an "Add Service Record" header action plus start/close/cancel
      transition actions; registered on `MaintenanceRequestResource::getRelations()`.
- [X] T134 [P] [US7] Create `ServiceRecords/Schemas/ServiceRecordForm.php`,
      `ServiceRecords/Schemas/ServiceRecordInfolist.php`
- [X] T135 [P] [US7] Create `ServiceRecords/Tables/ServiceRecordsTable.php`
      with overdue/due-soon/closed styling (color-coded due date + status
      badge), plus archive/restore actions and `TrashedFilter` for
      `MaintenanceTaskPolicy`'s bulk-action parity (T126).
- [X] T136 [US7] Create
      `ServiceRecords/Pages/{ListServiceRecords,ViewServiceRecord,
      EditServiceRecord}.php`
- [X] T137 [US7] **Revised**: as with T038/T074/T117, every US7 UI string is
      an inline Filament label — no bespoke `lang/en/admin.php` keys were
      needed beyond the already-present `admin.resources.service_records`.
- [X] T138 [US7] Run `php artisan test --compact --filter=ServiceRecord`
      (8/8) and confirm all US7 tests pass — command-only. Also re-ran
      `--filter=MaintenanceRequest` (10/10, including the deferred FR-066
      tests), `--filter=Support` (72/72), `--filter=CrossModulePermissionLeak`
      (6/6), `tests/Unit/ArchTest.php` (9/9), `phpstan analyse` (0 errors),
      `pint --dirty` (clean), and the full suite via
      `php artisan test --compact --parallel` (1272/1272) to confirm no
      regression anywhere else in the codebase.

**Checkpoint**: User Stories 2–7 all work independently.

---

## Phase 9: User Story 8 - Consume Spare Parts on a Service Record (Priority: P2)

**Goal**: Record spare parts a service record consumed, decrementing stock
via the existing Inventory services.

**Independent Test**: Record a consumption, verify stock and the movement,
attempt a consumption exceeding available stock, reverse a consumption —
using service records from Phase 8.

### Tests for User Story 8

- [X] T139 [P] [US8] Feature test: recording a consumption decrements stock
      and creates exactly one `InventoryMovement` referencing the service
      record, in `tests/Feature/Support/ServiceRecordPartTest.php`
- [X] T140 [P] [US8] Feature test: a consumption exceeding available stock
      is rejected naming the available quantity, no stock/movement change
- [X] T141 [P] [US8] Feature test: consumption record + stock decrement +
      movement are one transaction; a forced failure leaves none persisted
- [X] T142 [P] [US8] Feature test: a concurrent consumption that would drive
      stock negative is rejected under concurrency, no partial write
      (SC-009). **Note**: as with T017/T063, "concurrent" is proxied by
      sequential calls against the same row — the second call is rejected by
      the same `lockForUpdate()` row lock that would serialize real
      concurrent transactions, not merely by a prior read.
- [X] T143 [P] [US8] Feature test: reversing creates a compensating
      movement, restores stock, and never edits/deletes the original
      record or movement; full quantity only, no partial reversal
- [X] T144 [P] [US8] Feature test: only System Admin may reverse, including
      after the service record is closed; a Support Manager attempt is
      denied
- [X] T145 [P] [US8] Feature test: a closed or cancelled service record
      rejects a new consumption
- [X] T146 [P] [US8] Feature test: a user holding only the parts-consumption
      permission gains no Inventory dashboard access (FR-088), via
      `WarehousePolicy` — the same proxy
      `CrossModulePermissionLeakTest.php` already uses for "Inventory
      dashboard access"
- [X] T147 [P] [US8] Feature test: every part consumed against a
      maintenance request/service record is listed with variant, warehouse,
      quantity, actor, timestamp
- [X] T148 [P] [US8] Policy test: `MaintenanceTaskPolicy` consume/reverse
      abilities — page-open, direct-action, direct-service-call parity

### Implementation for User Story 8

- [X] T149 [US8] Create the `service_record_parts` migration per
      [data-model.md](./data-model.md) §8
- [X] T150 [US8] Create `app/Models/ServiceRecordPart.php` — immutable
      except the `reversed_*`/`reversal_movement_id` columns (a
      `booted()` guard rejecting any other dirty column, mirroring
      `TicketAssignment`; added to `tests/Unit/ArchTest.php`'s strict-preset
      exemption list for the same required-override reason). Also added
      `MaintenanceTask::parts()` (deferred from US7 per T128's own note).
- [X] T151 [P] [US8] Create
      `database/factories/ServiceRecordPartFactory.php` with a
      `reversed()` state
- [X] T152 [US8] Create `app/Services/Support/ServiceRecordPartService.php`
      — `consume()`/`reverse()` calling
      `InventoryBalanceService::transferOut()`/`transferIn()` and creating
      the `InventoryMovement` rows per
      [contracts/maintenance-lifecycle.md](./contracts/maintenance-lifecycle.md) §4.
      Also added `consume()`/`reverse()` abilities to `MaintenanceTaskPolicy`
      (no separate `ServiceRecordPartPolicy` exists — permissions.md and
      T148 both scope these abilities to `MaintenanceTaskPolicy`).
- [X] T153 [P] [US8] Create
      `ServiceRecords/RelationManagers/ConsumedPartsRelationManager.php`
      with Consume/Reverse `Action`s; registered on
      `ServiceRecordResource::getRelations()`. The insufficient-stock
      rejection is a `ValidationException`, which Filament's own Livewire
      action pipeline already surfaces as a field error automatically (no
      manual `catch` needed there) — only the closed/cancelled and
      already-reversed `DomainException` rejections get a manual
      `Notification`, matching every other Support relation manager.
- [X] T154 [US8] **Revised**: as with T038/T074/T117/T137, every US8 UI
      string is an inline Filament label — no bespoke `lang/en/admin.php`
      keys were needed. `MovementType::ServiceConsumption` and its
      `StockMovementsTable.php` badge color already existed from the
      Foundational phase (T006).
- [X] T155 [US8] Run `php artisan test --compact --filter=ServiceRecordPart`
      (11/11) and confirm all US8 tests pass — command-only. Also re-ran
      `--filter=Support` (83/83), `--filter=CrossModulePermissionLeak`
      (6/6), `tests/Unit/ArchTest.php` (9/9), `phpstan analyse` (0 errors),
      `pint --dirty` (clean), and the full suite via
      `php artisan test --compact --parallel` (1283/1283) to confirm no
      regression anywhere else in the codebase.

**Checkpoint**: User Stories 2–8 all work independently.

---

## Phase 10: User Story 9 - Search, Report, and Audit Across the Module (Priority: P3)

**Goal**: Search/filter across all three record types; view workload,
SLA-breach, resolution-time, and parts-consumption summaries.

**Independent Test**: Search and filter each record type and open each
report view using data created by the earlier stories.

### Tests for User Story 9

- [X] T156 [P] [US9] Feature test: ticket/maintenance-request/service-record
      lists all support search+filter+pagination, in
      `tests/Feature/Support/SupportReportTest.php`
- [X] T157 [P] [US9] Feature test: the workload report shows open tickets by
      status, priority, assignee
- [X] T158 [P] [US9] Feature test: the SLA report shows response/resolution
      breach counts and average resolution time for a chosen period
- [X] T159 [P] [US9] Feature test: the maintenance report shows open
      requests, overdue service records, and parts consumed per period
- [X] T160 [P] [US9] Feature test: every sensitive action (creation,
      transition, assignment, settlement, closure, maintenance transition,
      service-record transition, consumption, reversal) produces a
      retrievable audit entry with actor/timestamp/changed values (SC-012)
- [X] T161 [P] [US9] Policy test: report/audit view — System Admin, Support
      Manager, Reviewer allowed; Support Agent denied. **Revealed a gap**:
      the shared `AuditLogResource`'s policy only recognized
      `CrmPermission::AuditView`, which would have silently denied every
      Support role. Fixed as part of this task: `AuditLogPolicy::canViewAudit()`
      now accepts `SupportPermission::AuditView` too — additive, no existing
      CRM behavior changed (contracts/audit-log.md: "no Support-specific
      audit mechanism", one shared trail).

### Implementation for User Story 9

- [X] T162 [US9] Create `app/Services/Support/SupportReportService.php` —
      workload/SLA/maintenance/parts aggregates; self-checks
      (`canView()`/`authorizeView()`, mirrors `EmployeeReportService`)
- [X] T163 [US9] Create
      `app/Filament/Resources/SupportReports/SupportReportResource.php`
      (mirrors `EmployeeReportResource`'s shape exactly — nominal `$model`,
      `$shouldRegisterNavigation = false`, empty `form()`/passthrough
      `table()`, `canAccess()`/`canViewAny()` delegating to the service);
      wired into `AdminModuleRegistry.php`'s `reports` group.
- [X] T164 [P] [US9] Create `SupportReports/Pages/ViewSupportReports.php`.
      **Revised**: `EmployeeReportResource`'s own UI shape (tabs +
      Eloquent-query-backed table per report type) doesn't fit Support's
      reports — workload/SLA/maintenance are aggregate stat sections, not
      row lists. Built as a custom `Filament\Resources\Pages\Page` instead,
      modeled on `App\Filament\Pages\ModulePlaceholder`'s `$view`/
      `getViewData()` mechanism: a Blade view
      (`resources/views/filament/support-reports/view-support-reports.blade.php`)
      renders the three sections via Filament's own `x-filament::section`/
      `x-filament::input` components, with `#[Url] $from`/`$until` Livewire
      properties (`wire:model.live`) driving the SLA/maintenance period
      filter. This still mirrors the *service* pattern
      (self-checking, `EmployeeReportService`-shaped) — only the rendering
      layer diverges, deliberately.
- [X] T165 [US9] Wire the module's audit view (reusing the existing
      `AuditLogResource`, filtered to Support subject types) into the
      Tickets/MaintenanceRequests/ServiceRecords view pages — a "View Audit
      Trail" header `Action` linking to
      `AuditLogResource::getUrl('index', ['tableFilters' => [...]])`. Added
      a `subject_id` `Filter` to `AuditLogsTable.php` (previously only
      `subject_type`/`causer_id`/`description`/a `created_at` range existed)
      so the link can pre-filter to the exact record, not just its type.
- [X] T166 [US9] **Revised**: as with T038/T074/T117/T137/T154, every US9 UI
      string is an inline Filament label or Blade literal — no bespoke
      `lang/en/admin.php` keys were needed beyond the already-present
      `admin.resources.support_reports`.
- [X] T167 [US9] Run `php artisan test --compact --filter=SupportReport`
      (7/7) and confirm all US9 tests pass — command-only. Also re-ran
      `--filter=Support` (89/89), `--filter=CrossModulePermissionLeak`
      (6/6), `--filter=AuditLog` (4/4), `tests/Unit/ArchTest.php` (9/9),
      `phpstan analyse` (0 errors), `pint --dirty` (clean), and the full
      suite via `php artisan test --compact --parallel` (1290/1290) to
      confirm no regression anywhere else in the codebase.

**Checkpoint**: All nine user stories independently functional.

---

## Phase 11: Polish & Cross-Cutting Concerns

- [X] T168 [P] Run `vendor/bin/pint --dirty --format agent` and fix any
      formatting issues across every file touched above — clean on every
      pass throughout the feature; ran again here as the final sweep.
- [X] T169 [P] Run `vendor/bin/phpstan analyse`; confirm zero new baseline
      entries and shrink `phpstan-baseline.neon` for any Support-adjacent
      entry that no longer applies. 0 errors. Removed the `class.notFound`
      entries for `MaintenanceRequestResource`/`ServiceRecordResource` as
      each was created (US6/US7); `SlaPolicyResource`/`SupportReportResource`
      never had entries to begin with (T001's deferral). A final sweep
      confirms zero remaining `App\Filament\Resources\{Tickets,
      MaintenanceRequests,ServiceRecords,SlaPolicies,SupportReports}`,
      `App\Services\Support`, or Support-model entries anywhere in the
      baseline.
- [X] T170 Walk every scenario in [quickstart.md](./quickstart.md) manually
      against a freshly seeded database; fix any gap found. **Revised**:
      the sandboxed browser preview was unreachable in this session
      (navigation denied), so the walkthrough was done as a rigorous
      code-level equivalent instead — re-ran every scenario's own test
      command fresh, then audited every Filament page/RelationManager
      action that no test had ever driven through Filament's real
      Livewire/Action pipeline (many tests to that point called domain
      services directly, bypassing the UI layer entirely). That audit
      found and fixed two real defects a manual click-through would have
      caught: (1) `SlaPolicyForm`'s `priority` field crashed
      `EditSlaPolicy` on load — its `formatStateUsing` closure was typed
      to require a `TicketPriority` instance but Filament passes the raw
      string state to a `TextInput`; (2) `CreateMaintenanceRequest`'s
      `?ticket_id=` pre-fill read `request()->query()` directly, which
      doesn't survive Livewire's mount lifecycle — replaced with a
      Livewire `#[Url(as: 'ticket_id')]` property (matching
      `ModulePlaceholder`'s own pattern) — and its `mount()` handler was
      separately found to reset the whole form state (wiping the
      `warranty_status` default) by calling `$this->form->fill()` a
      second time instead of merging into `$this->data`. Added durable
      test coverage closing every gap the audit surfaced: the actual
      `CreateTicket` chargeable-toggle form flow, `CreateMaintenanceRequest`'s
      pre-fill and standalone paths, `EditTicket`/`EditMaintenanceRequest`/
      `EditServiceRecord`/`EditSlaPolicy` (all previously untested via
      Livewire), and `ServiceRecordsRelationManager`'s/
      `ConsumedPartsRelationManager`'s header and row actions (previously
      exercised only via direct service calls, never through Filament's
      own Action pipeline) — 10 new tests across
      `TicketPaymentTest.php`/`TicketIntakeTest.php`/
      `MaintenanceRequestTest.php`/`ServiceRecordTest.php`/
      `ServiceRecordPartTest.php`/`SlaTest.php`.
- [X] T171 Run `composer test` (full suite); confirm 100% type coverage and
      100% code coverage held, and no regression in any other module's
      suite (SC-014). `test:lint`'s `rector --dry-run` step surfaced pending
      mechanical fixes several times across the feature (13 files, then 1,
      then 11 more as coverage-closing work landed — `#[\Override]` on an
      overridden `booted()`, early-return style, blank-line-before-assignment,
      arrow-function conversion, encapsed-string-to-`sprintf`,
      newline-after-statement, quote-escape simplification) — each time
      applied via `vendor/bin/rector process` (behavior-neutral, confirmed
      by re-running the full Support suite after), then `pint --dirty` once
      more, until `rector --dry-run` reported zero remaining changes.
      The first `composer test` run surfaced a real coverage shortfall
      (97.7%, later 99.7%, then 99.9%) despite all functional tests passing;
      closed it with a mix of (a) genuine new tests for reachable gaps —
      including two more real defects a manual walkthrough would have
      caught: `TicketsTable`'s `close` action could hit the FR-026
      maintenance-request guard and `MaintenanceRequestsTable`'s `close`
      action could hit the FR-066 equivalent, neither previously exercised
      through the actual row action; and the `assign` action in
      `AssignmentsRelationManager` had no test for targeting a
      non-assignable ticket status — and (b) `@codeCoverageIgnoreStart/End`
      on defensive branches proven unreachable (an action's own `->visible()`
      gate already matches its service's only precondition exactly, or a
      service call-site's only real exception type doesn't match the
      catch clause at all). Final `composer test` run: `test:lint` clean,
      `test:types` 0 PHPStan errors, `test:type-coverage` 100.0%,
      `test:unit` 1356/1356 passing (6360 assertions, parallel), `test:coverage`
      100.0% code coverage — all green, no regression in any other module.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: no dependencies
- **Foundational (Phase 2)**: depends on Setup — BLOCKS every user story
- **User Stories (Phase 3–10)**: all depend on Foundational; within that,
  strict dependency chain below (not free-standing, unlike the generic
  template) because later stories extend earlier stories' tables/services
- **Polish (Phase 11)**: depends on all nine user stories being complete

### User Story Dependencies (real, not generic)

- **US2 (P1)**: after Foundational only — the first entity (`tickets`)
- **US3 (P1)**: after US2 — extends the same `Ticket` model/table,
  introduces `TicketLifecycleService` that US4/US5 both extend further
- **US4 (P1)**: after US3 — settlement calls into `TicketIntakeService`
  (US2) and `TicketLifecycleService` (US3)
- **US5 (P2)**: after US3 — hooks into `TicketLifecycleService`'s
  transition points; the `tickets` migration's SLA columns were already
  created in US2 but stay dormant until this phase
- **US6 (P2)**: after US2 — `maintenance_records.ticket_id` references
  `tickets`, and "raise from a ticket" needs `TicketResource` to exist, but
  US6 does not depend on US3/US4/US5
- **US7 (P2)**: after US6 — `maintenance_tasks.maintenance_record_id`
  references `maintenance_records`
- **US8 (P2)**: after US7 — `service_record_parts.maintenance_task_id`
  references `maintenance_tasks`, and calls the existing
  `InventoryBalanceService` (no new dependency introduced)
- **US9 (P3)**: after US2–US8 — its reports read every other story's data

This is a stricter chain than the generic template's "mostly independent"
default because every later Support entity's FK points at an earlier one
(`tickets` → `maintenance_records` → `maintenance_tasks` →
`service_record_parts`) — there is no meaningful way to build US7 before
US6, for example. Parallelism instead comes from within each phase (see
below), not across phases.

### Within Each User Story

- Tests are written first and confirmed failing before implementation
- Enums → migration → model → factory → policy → service → Filament
  resource → lang keys → test run
- Story complete (its own `--filter` run green) before moving to the next

### Parallel Opportunities

- All `[P]` tasks within Setup, Foundational, and each story's Tests
  subsection run in parallel (independent files)
- Within a story's Implementation subsection, model/factory/schema/table
  files marked `[P]` run in parallel; the migration, the service, and the
  resource's own PHP class are each a serialization point other `[P]` tasks
  in that story depend on

---

## Parallel Example: User Story 2

```bash
# Launch all Tests for User Story 2 together:
Task: "Feature test: ticket_number uniqueness ... TicketIntakeTest.php"
Task: "Feature test: required fields rejected ... TicketIntakeTest.php"
Task: "Feature test: concurrent ticket creation never duplicates a number"
Task: "Policy test: TicketPolicy page-open/direct/bulk/direct-service parity"

# Launch independent Implementation files for User Story 2 together:
Task: "Create app/Enums/TicketType.php"
Task: "Create app/Enums/TicketPriority.php"
Task: "Create app/Enums/TicketStatus.php"
Task: "Create database/factories/TicketFactory.php"
```

---

## Implementation Strategy

### MVP First (User Stories 2 + 3, both P1)

1. Complete Phase 1 (Setup) and Phase 2 (Foundational — CRITICAL, blocks
   everything)
2. Complete Phase 3 (US2 — ticket intake)
3. Complete Phase 4 (US3 — assignment and lifecycle)
4. **STOP and VALIDATE**: ticket intake + lifecycle work end to end per
   [quickstart.md](./quickstart.md) Scenarios 1–3
5. This is the smallest deployable increment that delivers real value (a
   logged, assignable, workable ticket) — US4/US5 (still P1/P2) add payment
   holds and SLA on top of it

### Incremental Delivery

Setup + Foundational → US2 → US3 → US4 → US5 → US6 → US7 → US8 → US9, each
checkpoint independently testable per [quickstart.md](./quickstart.md)'s
matching scenario, matching spec.md's own priority order (P1 stories first,
then P2, then P3).

---

## Notes

- `[P]` tasks touch different files with no unmet dependency
- `[Story]` labels trace every task back to spec.md's user stories
- Every status-transition rule lives in the enum + domain service, never
  only in a hidden Filament button (FR-006/008) — verified by this phase's
  own direct-service-call test, not assumed
- Commit after each task or logical group; stop at any checkpoint to
  validate a story independently
- No accounting/journal/tax table gains a row anywhere in this feature — a
  dedicated regression test (T066) checks this explicitly, not just an
  absence of code that would write one
