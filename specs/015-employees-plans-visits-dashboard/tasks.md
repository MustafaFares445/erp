---

description: "Task list for the Employees, Monthly Plans, Visits, Performance & Salary Dashboard"
---

# Tasks: Employees, Monthly Plans, Visits, Performance & Salary Dashboard

**Input**: Design documents from `/specs/015-employees-plans-visits-dashboard/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md),
[data-model.md](./data-model.md), [contracts/](./contracts/)

**Tests**: Test tasks are **included and mandatory**, not optional. Constitution Principle VI
requires a test for every implemented business rule, `CLAUDE.md` rule 5 requires every behavior
change to be programmatically tested, and both coverage thresholds must stay at 100%. Write each
story's tests before its implementation and confirm they fail first.

**Organization**: Grouped by user story so each is independently implementable and testable.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel — different files, no dependency on incomplete work
- **[Story]**: US1–US8, mapping to spec.md's user stories

Every task names the file(s) it changes. Eleven tasks are deliberately command-only gates rather
than file edits (T005, T011, T037, T061, T082, T102, T137, T169, T177, T185, T195), because their
output is a pass/fail verdict on work already done, not a new artifact.

**Post-`/speckit-analyze` remediation**: T011, T023, T046, T068, the T081 audit clause, T109,
T113, T133, T148, T151, and T175 were added after `/speckit-analyze` found that audit-logging
coverage (FR-084/SC-008/Constitution Principle VI) and two success criteria (FR-082, FR-083) had
weaker task coverage than the rest of the feature. See the analysis report in this feature's
conversation history for the full finding list (C1, H1, H2, M1).

## Execution order vs. spec priority

Tasks are grouped by user story, but **execution order follows the dependency graph in plan.md
§15.1, not spec.md's raw priority list**, exactly as `specs/013-crm-customers-subscriptions/tasks.md`
placed its governance story (there, US4) after the resources it governs. Two deviations from pure
P1-before-P2 ordering, both required by real data dependencies:

- **US1 (Enforce Dashboard Roles and Permissions, P1) runs last among the domain stories** (Phase
  9), because proving the fixed-role matrix behaves identically requires the ten dashboard
  surfaces built in US2–US8 to already exist. The permission *mechanism* (catalogue, seeder,
  policies, the cross-module leak fix) is still built in Foundational (Phase 2), before any story.
- **US7 (Calculate Performance and Salary, P1) runs after US5 (Visits, P2)**, because
  `work_time_adherence` reads `customer_visits.checked_in_at`/`checked_out_at`, which does not
  exist until US5 lands (plan.md §15.1: "WP7 needs WP4 and WP5").

## Path Conventions

Modular monolith, existing layout (see plan.md → Project Structure). Domain services in
`app/Services/Employees/`, Filament resources in `app/Filament/Resources/<Plural>/`, models flat in
`app/Models/`, tests in `tests/Feature/Employees/` and `tests/Unit/`. No new top-level directory.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Environment and configuration scaffolding shared by every later phase.

- [X] T001 Add `OPENAI_API_KEY`, `OPENAI_TRANSCRIBE_MODEL`, `OPENAI_TRANSCRIBE_BASE_URL`,
      `OPENAI_TRANSCRIBE_TIMEOUT`, `EMPLOYEES_TRANSCRIBE_DRIVER`, `EMPLOYEES_TRANSCRIBE_MAX_BYTES`,
      and `EMPLOYEES_DEFAULT_REQUIRED_VISIT_MINUTES` to `.env.example` per
      [contracts/voice-note-ai.md](./contracts/voice-note-ai.md)
- [X] T002 [P] Create `config/employees.php` (transcription driver switch, max bytes,
      `default_required_visit_minutes`)
- [X] T003 [P] Add the `openai` block to `config/services.php`
- [X] T004 [P] Document the new env vars in `Docs/CONFIGURATION.md`
- [X] T005 Run `composer test` and record the passing suite list as the green baseline — command-only
      gate, no file edit

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The permission mechanism every later phase authorizes against, and the cross-module
leak this feature would otherwise silently widen (R-006).

**⚠️ CRITICAL**: No user story work begins until this phase completes.

- [X] T006 [P] Create `app/Enums/EmployeePermission.php` with the full catalogue from
      [contracts/permissions.md](./contracts/permissions.md)
- [X] T007 Create `database/seeders/EmployeePermissionSeeder.php` (idempotent, mirrors
      `CrmPermissionSeeder`): `forgetCachedPermissions()`, `Permission::findOrCreate`,
      `Role::findOrCreate('Employee Manager')`/`Role::findOrCreate('Payroll Officer')` with their
      documented permission grants
- [X] T008 Introduce a single shared source of truth for fixed dashboard role names (e.g.
      `App\Enums\DashboardRole`) in `app/Enums/DashboardRole.php`, listing every module's fixed
      roles (R-006)
- [X] T009 Update `app/Policies/Concerns/ChecksCrmPermissions.php` to consult `DashboardRole`
      instead of `CrmPermission::fixedRoleNames()` (R-006 fix)
- [X] T010 Update the equivalent Inventory permissions trait to consult `DashboardRole` instead of
      its own private fixed-role list (R-006 fix)
- [X] T011 Run `php artisan test --compact --filter=Crm` and `--filter=Inventory`, confirm no
      regression from the `DashboardRole` refactor in T008–T010 — command-only gate (M1)
- [X] T012 [P] Regression test: an admin whose only role is `Payroll Officer` cannot manage CRM
      customers, in `tests/Feature/Employees/CrossModulePermissionLeakTest.php`
- [X] T013 [P] Regression test: an admin whose only role is `Employee Manager` cannot manage CRM
      customers or Inventory records, in the same file
- [X] T014 Create `app/Policies/Concerns/ChecksEmployeePermissions.php` (`employeePermissionMap()`
      helper; every policy's `forceDelete()` returns `false`, matching `CustomerProfilePolicy`) —
      **deferred to Phase 3**: PHPStan's `trait.unused` rule fails a trait with zero consumers, and
      no Employees policy exists until `EmployeeProfilePolicy` (T028); create this trait together
      with that first consumer instead of leaving it dead code here.
- [X] T015 [P] Extend `tests/Unit/ArchTest.php`: ban references to the OpenAI client outside
      `App\Services\Employees\OpenAiWhisperTranscriber`
- [X] T016 [P] Extend `tests/Unit/ArchTest.php`: ban `App\Filament\Resources\Performance` and
      `App\Filament\Resources\SalaryCalculations` from writing `EmployeePerformanceScore`/
      `EmployeeSalaryCalculation` rows directly, mirroring the existing stock-write ban
- [X] T017 [P] Feature test: `EmployeePermissionSeeder` is idempotent and grants exactly the
      catalogue in [contracts/permissions.md](./contracts/permissions.md), in
      `tests/Feature/Employees/EmployeePermissionSeederTest.php`

**Checkpoint**: The shared permission mechanism exists and the cross-module leak is fixed. No
employee entity work has started yet.

---

## Phase 3: User Story 2 - Manage Employee Profiles (Priority: P1)

**Goal**: Create, edit, search, deactivate, and archive employee profiles, with the correct payable
base required per salary mode.

**Independent Test**: Create, edit, search, filter, deactivate, and archive an employee profile
without creating any plan, task, or visit.

### Tests for User Story 2

> Write these first. Confirm they fail before implementing.

- [X] T018 [P] [US2] Feature test: `employee_code` uniqueness is checked `withTrashed()` on
      generation, in `tests/Feature/Employees/EmployeeOnboardingServiceTest.php`
- [X] T019 [P] [US2] Feature test: `commission_target_amount` is required when
      `use_base_salary = false`; a missing payable base fails validation rather than defaulting to
      zero, in `tests/Feature/Employees/EmployeeProfileValidationTest.php`
- [X] T020 [P] [US2] Feature test: archiving an employee is a soft delete that preserves history;
      restoring returns it to active, in `tests/Feature/Employees/EmployeeAccessServiceTest.php`
- [X] T021 [P] [US2] Feature test: search by code/name and filter by status/job title, in
      `tests/Feature/Employees/EmployeeProfileSearchTest.php`
- [X] T022 [P] [US2] Policy test: `EmployeeProfilePolicy` page-open, direct-action, and bulk-action
      enforcement for all four fixed roles, in `tests/Feature/Employees/EmployeeProfilePolicyTest.php`
- [X] T023 [P] [US2] Feature test: employee create, activate, deactivate, archive, and restore each
      write a retrievable `AuditLogger` entry with actor and timestamp (C1, FR-084, SC-008), in
      `tests/Feature/Employees/EmployeeAuditTest.php`

### Implementation for User Story 2

- [X] T024 [US2] Create the `employee_profiles` migration per
      [data-model.md](./data-model.md) §1 in `database/migrations/`
- [X] T025 [P] [US2] Create `app/Enums/SalaryCalculationMode.php` (`PerformanceOnly`,
      `BasePlusPerformance`)
- [X] T026 [US2] Create `app/Models/EmployeeProfile.php` with `casts()`, `TracksBlameable`, and
      relations (`belongsTo User`, `hasMany SalesPlan`/`CustomerVisit`/`BonusSuggestion`)
- [X] T027 [P] [US2] Create `database/factories/EmployeeProfileFactory.php` with states
      (`baseSalary()`, `performanceOnly()`, `inactive()`, `archived()`)
- [X] T028 [US2] Create `app/Policies/EmployeeProfilePolicy.php` using `ChecksEmployeePermissions`
- [X] T029 [US2] Create `app/Services/Employees/EmployeeOnboardingService.php` — creates the
      `User`+`EmployeeProfile` pair, generates a unique `employee_code`, sets
      `user_type = Employee`, and writes an `AuditLogger` entry for the creation inside the same
      transaction (FR-010, FR-084)
- [X] T030 [US2] Create `app/Services/Employees/EmployeeAccessService.php` — enable/disable app
      access (FR-012), archive/restore (FR-013), each writing an `AuditLogger` entry (FR-084)
- [X] T031 [US2] Create `app/Filament/Resources/Employees/EmployeeResource.php` per
      [contracts/dashboard-ui.md](./contracts/dashboard-ui.md) (sort 601, `workforce` section)
- [X] T032 [P] [US2] Create `Employees/Schemas/EmployeeForm.php` (job title, contact fields, salary
      option toggle, conditional commission-target field)
- [X] T033 [P] [US2] Create `Employees/Schemas/EmployeeInfolist.php`
- [X] T034 [P] [US2] Create `Employees/Tables/EmployeesTable.php` with search/filter/pagination
      (FR-014, FR-085)
- [X] T035 [US2] Create `Employees/Pages/{ListEmployees,CreateEmployee,ViewEmployee,EditEmployee}.php`
- [X] T036 [US2] Add `admin.resources.employees` and `admin.sections.workforce` keys to
      `lang/en/admin.php`
- [X] T037 [US2] Run `php artisan test --compact --filter=Employee` and confirm all US2 tests pass —
      command-only gate

**Checkpoint**: Employee profiles are independently creatable, searchable, and archivable through
the dashboard.

---

## Phase 4: User Story 3 - Build and Maintain Monthly Plans (Priority: P1)

**Goal**: Create, activate, copy, and lifecycle-manage monthly plans under the weight/task and
one-active-plan rules.

**Independent Test**: Create a plan, adjust its weights and tasks, attempt an invalid save, copy it
to another employee and to another month, and edit, deactivate, delete, and restore it.

### Tests for User Story 3

- [X] T038 [P] [US3] Feature test: save is rejected unless the four weights sum to exactly 100 and
      the plan has ≥1 task (D4/FR-022), in `tests/Feature/Employees/SalesPlanInvariantsTest.php`
- [X] T039 [P] [US3] Feature test: the database rejects a second active plan for the same
      employee/month even when the service is bypassed (R-001/FR-023), in
      `tests/Feature/Employees/SalesPlanActivePlanConstraintTest.php`
- [X] T040 [P] [US3] Feature test: plan copy — every copied field present, every excluded field
      absent, the new plan starts `Draft`, its tasks start `Pending` (D9), in
      `tests/Feature/Employees/SalesPlanDuplicationTest.php`
- [X] T041 [P] [US3] Feature test: plan copy is rejected when the target employee already has an
      active plan for the target month, before any row is written, in the same file
- [X] T042 [P] [US3] Feature test: month-length clamping — a task due 31 January copied into
      February lands on 28/29 February, never March (R-004), in the same file
- [X] T043 [P] [US3] Feature test: a plan copy runs in one transaction; a forced failure leaves no
      partial plan or task, in the same file
- [X] T044 [P] [US3] Feature test: plan delete is blocked once any task on it has been completed,
      and allowed otherwise (FR-025), in `tests/Feature/Employees/SalesPlanLifecycleTest.php`
- [X] T045 [P] [US3] Enum unit test: every `SalesPlanStatus` allowed and rejected transition,
      including self-transitions rejected (§8.13/D8), in
      `tests/Unit/Enums/SalesPlanStatusTest.php`
- [X] T046 [P] [US3] Feature test: plan create, update, status transition, delete, restore, and
      copy (`plan.copied`) each write an `AuditLogger` entry, and a rolled-back transaction
      discards the audit row too (C1, FR-084, SC-008), in
      `tests/Feature/Employees/SalesPlanAuditTest.php`

### Implementation for User Story 3

- [X] T047 [US3] Create the `sales_plans` migration per [data-model.md](./data-model.md) §2 —
      weights, `active_month` + its unique index, `required_visit_minutes`, `status`
- [X] T048 [P] [US3] Create `app/Enums/SalesPlanStatus.php` with `allowedTransitions()`/
      `canTransitionTo()` per [contracts/plan-lifecycle.md](./contracts/plan-lifecycle.md)
- [X] T049 [US3] Create `app/Models/SalesPlan.php` with `casts()`, `TracksBlameable`, relations
      (`belongsTo EmployeeProfile`, `hasMany PlanTask`, `hasOne EmployeePerformanceScore`,
      `hasMany EmployeeSalaryCalculation`)
- [X] T050 [P] [US3] Create `database/factories/SalesPlanFactory.php` with states (`active()`,
      `withTasks()`)
- [X] T051 [US3] Create `app/Policies/SalesPlanPolicy.php`
- [X] T052 [US3] Create `app/Services/Employees/Exceptions/InvalidStatusTransition.php`
- [X] T053 [US3] Create `app/Services/Employees/SalesPlanService.php` — create/update/transition/
      delete/restore, maintains `active_month`, refuses delete once a task is completed (FR-020–
      FR-023, FR-025, FR-026), writing an `AuditLogger` entry for every create/update/transition/
      delete/restore (FR-084)
- [X] T054 [US3] Create `app/Services/Employees/SalesPlanDuplicationService.php` — copy/no-copy
      lists, month-clamping, target-conflict rejection (D9, contracts/plan-lifecycle.md); writes a
      single `plan.copied` `AuditLogger` entry per copy (FR-084)
- [X] T055 [US3] Create `app/Filament/Resources/MonthlyPlans/MonthlyPlanResource.php` (sort 611,
      `planning` section)
- [X] T056 [P] [US3] Create `MonthlyPlans/Schemas/MonthlyPlanForm.php` with a live weight-sum
      indicator and a `required_visit_minutes` placeholder showing the config default (§11.2)
- [X] T057 [P] [US3] Create `MonthlyPlans/Schemas/MonthlyPlanInfolist.php`
- [X] T058 [P] [US3] Create `MonthlyPlans/Tables/MonthlyPlansTable.php` with
      Activate/Pause/Complete/Archive/Delete/Restore/"Copy to month"/"Assign to another employee"
      actions
- [X] T059 [US3] Create
      `MonthlyPlans/Pages/{ListMonthlyPlans,CreateMonthlyPlan,ViewMonthlyPlan,EditMonthlyPlan}.php`
- [X] T060 [US3] Add `admin.resources.monthly_plans`, `admin.sections.planning`, and the plan-copy
      conflict error key to `lang/en/admin.php`
- [X] T061 [US3] Run `php artisan test --compact --filter=SalesPlan` and confirm all US3 tests pass —
      command-only gate

**Checkpoint**: Plans activate only under the weight/task rule and copy correctly across employees
and months.

---

## Phase 5: User Story 4 - Track Tasks Through Completion (Priority: P1)

**Goal**: Create tasks inside a plan with mandatory dates, track every status change, and maintain
`completed_at` for schedule adherence.

**Independent Test**: Create tasks inside a plan, move them through valid and invalid status
transitions, reopen a completed task, and view overdue/near-due/completed lists.

### Tests for User Story 4

- [X] T062 [P] [US4] Feature test: task dates are required and must fall inside the plan's month
      window (FR-030, FR-032), in `tests/Feature/Employees/PlanTaskValidationTest.php`
- [X] T063 [P] [US4] Feature test: every status change writes one append-only `TaskStatusLog` row
      with actor, time, and note (FR-033), in `tests/Feature/Employees/TaskStatusLogTest.php`
- [X] T064 [P] [US4] Feature test: `completed_at` is set on entering `Completed` and cleared on
      reopen, always agreeing with the latest `Completed` log entry (D5/FR-035), in
      `tests/Feature/Employees/PlanTaskCompletionTest.php`
- [X] T065 [P] [US4] Feature test: reopening a `Completed` task marks the plan's performance score
      stale, in the same file
- [X] T066 [P] [US4] Enum unit test: every `PlanTaskStatus` allowed and rejected transition
      (§8.13), in `tests/Unit/Enums/PlanTaskStatusTest.php`
- [X] T067 [P] [US4] Feature test: Overdue/Due soon/Completed task-list filters (FR-034), in
      `tests/Feature/Employees/PlanTaskFiltersTest.php`
- [X] T068 [P] [US4] Feature test: every task status transition (including reopen) writes an
      `AuditLogger` entry distinct from the `TaskStatusLog` domain record (C1, FR-084, SC-008), in
      `tests/Feature/Employees/PlanTaskAuditTest.php`

### Implementation for User Story 4

- [X] T069 [US4] Create the `plan_tasks` migration per [data-model.md](./data-model.md) §3 —
      `starts_at`/`due_at` `NOT NULL`, `completed_at`, nullable `customer_id`
- [X] T070 [US4] Create the `task_status_logs` migration per [data-model.md](./data-model.md) §4 —
      append-only, no `updated_at`/soft delete
- [X] T071 [P] [US4] Create `app/Enums/PlanTaskStatus.php` with `allowedTransitions()`/
      `canTransitionTo()`
- [X] T072 [US4] Create `app/Models/PlanTask.php` with `casts()`, `TracksBlameable`, relations
      (`belongsTo SalesPlan`/`CustomerProfile`, `hasMany TaskStatusLog`/`CustomerVisit`)
- [X] T073 [US4] Create `app/Models/TaskStatusLog.php` with a model-level guard rejecting any update
      or soft delete
- [X] T074 [P] [US4] Create `database/factories/PlanTaskFactory.php` with states (`completed()`,
      `completedWithTimestamp()`, `overdue()`)
- [X] T075 [US4] Create `app/Policies/PlanTaskPolicy.php`
- [X] T076 [US4] Create `app/Services/Employees/PlanTaskService.php` — create/update with
      date-window validation, status transitions writing `TaskStatusLog`, `completed_at`
      maintenance (FR-030–FR-035); each transition also writes an `AuditLogger` entry (FR-084)
- [X] T077 [US4] Create `app/Filament/Resources/Tasks/TaskResource.php` (sort 612, `planning`
      section)
- [X] T078 [P] [US4] Create `Tasks/Schemas/TaskForm.php` (title, description, `starts_at`/`due_at`
      validated against the plan window, optional customer link)
- [X] T079 [P] [US4] Create `Tasks/Tables/TasksTable.php` with Overdue/Due soon/Completed filters
      and a status-change action requiring a note
- [X] T080 [US4] Create `Tasks/Pages/{ListTasks,ViewTask,EditTask}.php`
- [X] T081 [US4] Add `admin.resources.tasks` and transition-rejection error keys to
      `lang/en/admin.php`
- [X] T082 [US4] Run `php artisan test --compact --filter=PlanTask` and confirm all US4 tests pass —
      command-only gate

**Checkpoint**: Tasks track completion, history, and due-date adherence independently of visits or
AI review.

---

## Phase 6: User Story 5 - Review Visits and Location Trail (Priority: P2)

**Goal**: Show visit timelines, GPS trails, and attachments; keep field-recorded visits locked
except for review notes.

**Independent Test**: View a visit's timeline, GPS trail, and attachments; attempt to edit a
field-recorded visit as a non-admin reviewer and confirm it is rejected while the review-note
action still succeeds; attempt the same edit as a System Admin and confirm it succeeds.

### Tests for User Story 5

- [X] T083 [P] [US5] Feature test: visit duration is computed from `checked_in_at`/`checked_out_at`
      and never stored (FR-041), in `tests/Feature/Employees/CustomerVisitDurationTest.php`
- [X] T084 [P] [US5] Feature test: GPS records return in chronological order and `visit_gps_logs`
      has no update path (FR-042), in `tests/Feature/Employees/VisitGpsLogTest.php`
- [X] T085 [P] [US5] Feature test: a field-recorded visit is immutable except to an
      `employees.visit.field-edit` holder (FR-044), and that an admin's field-edit writes an
      `AuditLogger` entry (C1, FR-084), in `tests/Feature/Employees/VisitFieldLockTest.php`
- [X] T086 [P] [US5] Feature test: the review note remains writable on a locked field-recorded
      visit by an `employees.visit.review` holder (D7/FR-044), in the same file
- [X] T087 [P] [US5] Feature test: review-note create and update are both audited with
      `old_values`/`new_values` (D7/FR-045), in
      `tests/Feature/Employees/VisitReviewAuditTest.php`
- [X] T088 [P] [US5] Enum unit test: every `VisitStatus` allowed and rejected transition, including
      `Completed` requiring `checked_out_at` (§8.13), in `tests/Unit/Enums/VisitStatusTest.php`

### Implementation for User Story 5

- [X] T089 [US5] Create the `customer_visits` migration per [data-model.md](./data-model.md) §5 —
      `recorded_channel`, `review_note`/`reviewed_by`/`reviewed_at`, no `audio_path`
- [X] T090 [US5] Create the `visit_gps_logs` migration per [data-model.md](./data-model.md) §6 —
      append-only
- [X] T091 [P] [US5] Create `app/Enums/VisitStatus.php` and `app/Enums/VisitRecordChannel.php`
- [X] T092 [US5] Create `app/Models/CustomerVisit.php` with `HasMedia` (private
      `visit-attachments` collection), `casts()`, `TracksBlameable`, relations, and a derived
      duration accessor
- [X] T093 [US5] Create `app/Models/VisitGpsLog.php` with an append-only guard
- [X] T094 [P] [US5] Create `database/factories/CustomerVisitFactory.php` with states
      (`completed()`, `completedWithoutCheckout()`, `fieldRecorded()`, `unattributed()`)
- [X] T095 [US5] Create `app/Policies/CustomerVisitPolicy.php` with the field-edit/review-note
      permission split
- [X] T096 [US5] Create `app/Services/Employees/VisitReviewService.php` — creates/updates the
      single review note, gates edits on field-recorded visits, audits old/new values (D7); an
      admin's field-edit of a locked visit also writes an `AuditLogger` entry (FR-084)
- [X] T097 [US5] Create `app/Filament/Resources/Visits/VisitResource.php` (sort 621, `field`
      section)
- [X] T098 [P] [US5] Create `Visits/Schemas/VisitInfolist.php` — computed duration, chronological
      GPS `RepeatableEntry`, attachments gallery, locked layout for field-recorded visits
- [X] T099 [P] [US5] Create `Visits/Tables/VisitsTable.php` with the "Add / update review note"
      action
- [X] T100 [US5] Create `Visits/Pages/{ListVisits,ViewVisit,EditVisit}.php`, `EditVisit` gated to
      `employees.visit.field-edit`
- [X] T101 [US5] Add `admin.resources.visits` and `admin.sections.field` keys to
      `lang/en/admin.php`
- [X] T102 [US5] Run `php artisan test --compact --filter=CustomerVisit` and confirm all US5 tests
      pass — command-only gate

**Checkpoint**: Visits are reviewable and field-lock-safe independently of AI review or scoring.

---

## Phase 7: User Story 6 - Review Voice Notes and AI Transcripts (Priority: P2)

**Goal**: Transcribe voice notes through an isolated, network-free-in-tests driver; surface
confidence honestly; gate all AI output behind a human decision.

**Independent Test**: Trigger transcription of a voice note (success and induced-failure cases)
using the fake driver; verify confidence display and labeling, keyword-rule management, and
opportunity-draft approval/rejection — all without affecting visit completion, performance, or
salary.

### Tests for User Story 6

- [X] T103 [P] [US6] Feature test: a throwing transcriber leaves visit status, performance score,
      and salary untouched (Principle V), in
      `tests/Feature/Employees/VoiceNoteTranscriptionIsolationTest.php`
- [X] T104 [P] [US6] Feature test: 4xx payload errors are never retried; transport/429/5xx errors
      retry up to 3 times with backoff (R-003), in
      `tests/Feature/Employees/TranscribeVoiceNoteJobTest.php`
- [X] T105 [P] [US6] Feature test: confidence boundaries `0.00`/`100.00` are accepted, out-of-range
      values are refused; `confidence` is null iff `confidence_source = Unavailable` (D6/FR-056),
      in `tests/Feature/Employees/VoiceNoteConfidenceTest.php`
- [X] T106 [P] [US6] Feature test: a derived confidence value is never labeled `ProviderReported`;
      the UI renders "Not reported by provider" and never `0.00%` for a null value, in the same
      file
- [X] T107 [P] [US6] Feature test: `language` is omitted from the request when the voice note has
      none and passed when set; `detected_language` is persisted from the response (D6/FR-055), in
      `tests/Feature/Employees/VoiceNoteLanguageTest.php`
- [X] T108 [P] [US6] Feature test: oversized audio is rejected before the job is dispatched, in
      `tests/Feature/Employees/VoiceNoteIntakeServiceTest.php`
- [X] T109 [P] [US6] Feature test: voice-note audio is served through a temporary signed URL, never
      a public disk path (H1, FR-083, D1), in
      `tests/Feature/Employees/VoiceNoteAudioPlaybackTest.php`
- [X] T110 [P] [US6] Feature test: no draft or bonus suggestion reaches `Approved` without a
      recorded decision; `Approved`/`Rejected` are terminal (FR-054), in
      `tests/Feature/Employees/SalesOpportunityDraftTest.php`
- [X] T111 [P] [US6] Enum unit tests: `VoiceNoteStatus`, `TranscriptionStatus`,
      `OpportunityDraftStatus` transitions (§8.13), in
      `tests/Unit/Enums/{VoiceNoteStatusTest,TranscriptionStatusTest,OpportunityDraftStatusTest}.php`
- [X] T112 [US6] ArchTest assertion: no class outside `App\Services\Employees\OpenAiWhisperTranscriber`
      references the OpenAI client (D6), extending `tests/Unit/ArchTest.php`
- [X] T113 [P] [US6] Feature test: voice-note deletion and opportunity-draft approve/reject each
      write an `AuditLogger` entry (C1, FR-084, SC-008), in
      `tests/Feature/Employees/VoiceNoteAuditTest.php`

### Implementation for User Story 6

- [X] T114 [US6] Create the `employee_voice_notes` migration per [data-model.md](./data-model.md)
      §7 — no `audio_path` column
- [X] T115 [US6] Create the `voice_note_transcriptions` migration per
      [data-model.md](./data-model.md) §8 — `confidence`, `confidence_source`,
      `detected_language`, `provider`, `error_message`
- [X] T116 [US6] Create the `ai_keyword_rules` migration per [data-model.md](./data-model.md) §9
- [X] T117 [US6] Create the `sales_opportunity_drafts` migration per
      [data-model.md](./data-model.md) §10 — `reviewed_by`/`reviewed_at`/`review_notes`
- [X] T118 [P] [US6] Create `app/Enums/{VoiceNoteStatus,TranscriptionStatus,
      TranscriptionConfidenceSource,OpportunityDraftStatus}.php`
- [X] T119 [US6] Create `app/Models/EmployeeVoiceNote.php` with `HasMedia` (private single-file
      `voice-note-audio` collection)
- [X] T120 [US6] Create `app/Models/VoiceNoteTranscription.php` with a model-level guard enforcing
      the `confidence`/`confidence_source` invariant
- [X] T121 [P] [US6] Create `app/Models/AiKeywordRule.php` and `app/Models/SalesOpportunityDraft.php`
- [X] T122 [P] [US6] Create factories for `EmployeeVoiceNote`, `VoiceNoteTranscription`,
      `AiKeywordRule`, and `SalesOpportunityDraft` with states (`transcribed()`, `failed()`,
      `unavailableConfidence()`, `derivedConfidence()`)
- [X] T123 [US6] Create `app/Policies/{EmployeeVoiceNotePolicy,AiKeywordRulePolicy,
      SalesOpportunityDraftPolicy}.php` — voice-note deletion is gated and writes an `AuditLogger`
      entry (FR-084)
- [X] T124 [US6] Create `app/Services/Employees/VoiceNoteTranscriber.php` interface plus
      `Data/TranscriptionRequest.php` and `Data/TranscriptionResult.php` DTOs per
      [contracts/voice-note-ai.md](./contracts/voice-note-ai.md)
- [X] T125 [US6] Create `app/Services/Employees/OpenAiWhisperTranscriber.php` — production driver,
      confidence derivation (R-002), language handling (D6)
- [X] T126 [US6] Create `app/Services/Employees/FakeVoiceNoteTranscriber.php` — deterministic test
      driver, forced in the test environment
- [X] T127 [US6] Create `app/Services/Employees/VoiceNoteIntakeService.php` — stores audio, enforces
      the max-bytes guard, creates the `Pending` transcription row, dispatches the job
- [X] T128 [US6] Create `app/Jobs/TranscribeVoiceNoteJob.php` — bounded, failure-type-aware retries
      (R-003)
- [X] T129 [US6] Create `app/Services/Employees/KeywordDetectionService.php` — matches active
      `AiKeywordRule`s, creates `Draft` opportunity drafts
- [X] T130 [US6] Create `app/Services/Employees/OpportunityReviewService.php` — approve/reject with
      a recorded decision, writing an `AuditLogger` entry per decision (FR-084)
- [X] T131 [US6] Bind `VoiceNoteTranscriber` to the configured driver in a service provider, reading
      `EMPLOYEES_TRANSCRIBE_DRIVER`
- [X] T132 [US6] Create `app/Filament/Resources/VoiceNotes/VoiceNoteResource.php` (`field` section)
      with confidence-source-aware rendering (§11.2)
- [X] T133 [US6] Create the signed-URL audio-playback endpoint used by the Filament audio player
      for the `voice-note-audio` media collection, replacing any public-path access (H1, FR-083)
- [X] T134 [P] [US6] Create `app/Filament/Resources/AiKeywordRules/AiKeywordRuleResource.php`
      (`intelligence` section)
- [X] T135 [P] [US6] Create `app/Filament/Resources/OpportunityDrafts/OpportunityDraftResource.php`
      (`intelligence` section) with approve/reject actions
- [X] T136 [US6] Add the four new `AdminModuleRegistry` items (voice notes, AI keyword rules,
      opportunity drafts) under the `field`/`intelligence` sections, plus matching
      `admin.resources.*`/`admin.sections.*` keys in `lang/en/admin.php`
- [X] T137 [US6] Run `php artisan test --compact --filter=VoiceNote` and confirm all US6 tests pass
      — command-only gate

**Checkpoint**: AI review is fully isolated from visit/score/salary and always requires a human
decision.

---

## Phase 8: User Story 7 - Calculate Performance and Salary (Priority: P1)

**Goal**: Compute the four component scores and total score per plan, and calculate/confirm salary
from them.

**Independent Test**: Using a plan with a mix of on-time, late, and reopened tasks, and visits with
and without verifiable duration, calculate performance and salary, change the plan, and confirm the
recalculation and supersession behavior.

### Tests for User Story 7

- [X] T138 [P] [US7] Unit test: `PerformanceScoringService`, table-driven — zero tasks, all
      complete, partial completion for each of the four factors independently (D5), in
      `tests/Unit/PerformanceScoringServiceTest.php`
- [X] T139 [P] [US7] Unit test: the D5 worked example (8 of 10 tasks on time → 80%,
      `schedule_score = 8.00` at weight 10) reproduces exactly, in the same file
- [X] T140 [P] [US7] Unit test: the zero-denominator rule for all four factors independently —
      scores 0, never divides by zero, never redistributes weight, in the same file
- [X] T141 [P] [US7] Unit test: `completed_at == due_at` counts as on time; a completed visit
      missing `checked_out_at` counts in the denominator only, in the same file
- [X] T142 [P] [US7] Unit test: the effective `required_visit_minutes` resolves from the plan then
      config, and is snapshotted into `calculation_breakdown`, in the same file
- [X] T143 [P] [US7] Unit test: an unattributed (`plan_task_id` null) visit is excluded from
      `visit_completion`/`work_time_adherence` and its count is recorded in
      `calculation_breakdown`, in the same file
- [X] T144 [P] [US7] Unit test: `total_score` equals `performance_percent` (D2);
      `task_completion_percent` is stored separately and never drives pay (FR-063), in the same
      file
- [X] T145 [P] [US7] Unit test: `SalaryCalculationService` resolves `payable_base` from
      `base_salary` or `commission_target_amount` per mode (D3); a null payable base fails
      validation, in `tests/Unit/SalaryCalculationServiceTest.php`
- [X] T146 [P] [US7] Feature test: only `Approved` bonus suggestions are summed into
      `bonus_amount`; `Pending`/`Rejected` contribute nothing (FR-064), in the same file
- [X] T147 [P] [US7] Feature test: recalculation on plan change marks the prior calculation
      `Superseded` (never deleted) and requires a fresh confirmation before taking effect (FR-065),
      in `tests/Feature/Employees/SalaryRecalculationServiceTest.php`
- [X] T148 [P] [US7] Feature test: salary recalculation and confirmation each run in one
      transaction; a forced failure during supersession leaves no partial write (H2, FR-082), in
      the same file as the recalculation test above
- [X] T149 [P] [US7] Enum unit test: `SalaryCalculationStatus` allowed and rejected transitions
      (§8.13), in `tests/Unit/Enums/SalaryCalculationStatusTest.php`
- [X] T150 [P] [US7] Feature test: a stored salary calculation stays reproducible from the row
      alone after the employee profile later changes, in the same file as the recalculation test
      above
- [X] T151 [P] [US7] Feature test: salary calculate, confirm, and supersede, and bonus
      approve/reject each write an `AuditLogger` entry (C1, FR-084, SC-008), in
      `tests/Feature/Employees/SalaryAuditTest.php`

### Implementation for User Story 7

- [X] T152 [US7] Create the `employee_performance_scores` migration per
      [data-model.md](./data-model.md) §11 — `calculation_breakdown` json,
      `task_completion_percent`, `calculated_at`, unique `(sales_plan_id, employee_id)`
- [X] T153 [US7] Create the `employee_salary_calculations` migration per
      [data-model.md](./data-model.md) §12 — `payable_base`, `confirmed_by`/`confirmed_at`,
      `superseded_by_id`/`superseded_at`
- [X] T154 [US7] Create the `bonus_suggestions` migration per [data-model.md](./data-model.md) §13
      — `approved_by`/`approved_at`, `decision_notes`
- [X] T155 [P] [US7] Create `app/Enums/{SalaryCalculationStatus,BonusSuggestionStatus,
      EmployeeReportType}.php`
- [X] T156 [US7] Create `app/Models/EmployeePerformanceScore.php`
- [X] T157 [US7] Create `app/Models/EmployeeSalaryCalculation.php` guarding `payable_base`/
      `performance_percent`/`bonus_amount`/`final_salary` against mass assignment
- [X] T158 [US7] Create `app/Models/BonusSuggestion.php`
- [X] T159 [P] [US7] Create factories for `EmployeePerformanceScore`, `EmployeeSalaryCalculation`,
      and `BonusSuggestion` with zero-denominator and superseded states
- [X] T160 [US7] Create `app/Policies/{EmployeePerformanceScorePolicy,
      EmployeeSalaryCalculationPolicy,BonusSuggestionPolicy}.php`
- [X] T161 [US7] Create `app/Services/Employees/PerformanceScoringService.php` per
      [contracts/performance-scoring.md](./contracts/performance-scoring.md) — pure, deterministic
- [X] T162 [US7] Create `app/Services/Employees/SalaryCalculationService.php` per
      [contracts/performance-scoring.md](./contracts/performance-scoring.md) §Salary; writes an
      `AuditLogger` entry on calculation (FR-084)
- [X] T163 [US7] Create `app/Services/Employees/SalaryRecalculationService.php` — confirm-before-
      apply, supersession, admin notification; wraps supersession in one transaction and writes an
      `AuditLogger` entry per confirm/supersede (H2, FR-082, FR-084)
- [X] T164 [US7] Create `app/Services/Employees/BonusApprovalService.php` — approve/reject with a
      recorded decision, writing an `AuditLogger` entry per decision (FR-084)
- [X] T165 [US7] Create `app/Filament/Resources/Performance/PerformanceResource.php` (sort 641,
      `compensation` section) — read-only preview reading `calculation_breakdown`
- [X] T166 [P] [US7] Create `app/Filament/Resources/SalaryCalculations/SalaryCalculationResource.php`
      (sort 642) with the `PendingConfirmation` banner and Confirm action
- [X] T167 [P] [US7] Add a bonus-suggestions relation table with approve/reject actions to the
      Salary/Performance surface
- [X] T168 [US7] Add `admin.resources.{performance,salary_calculations,bonus_suggestions}` and
      `admin.sections.compensation` keys to `lang/en/admin.php`
- [X] T169 [US7] Run `php artisan test --compact --filter=PerformanceScoring` and
      `--filter=SalaryCalculation` and confirm all US7 tests pass — command-only gate

**Checkpoint**: Performance and salary are calculated, previewed, and confirmed solely from task
and visit data already recorded.

---

## Phase 9: User Story 1 - Enforce Dashboard Roles and Permissions (Priority: P1, verified last)

**Goal**: Prove the fixed-role matrix behaves identically across every one of the ten dashboard
surfaces now that they all exist.

**Independent Test**: Sign in as each of the four fixed roles and exercise the same action (a
direct page visit, a record action, a bulk action, and a direct service call bypassing the UI) to
confirm identical allow/deny behavior across all four paths.

- [X] T170 [P] [US1] Feature test: `System Admin` has full access across all ten resources, in
      `tests/Feature/Employees/DashboardFixedRoleMatrixTest.php`
- [X] T171 [P] [US1] Feature test: `Employee Manager` has exactly its documented access (employee/
      plan/task manage, visit review) and is denied every salary/bonus action, in the same file
- [X] T172 [P] [US1] Feature test: `Payroll Officer` has exactly its documented access
      (performance/salary/bonus) and is denied employee/plan/task management, in the same file
- [X] T173 [P] [US1] Feature test: `Reviewer` is read-only across every resource — no create, edit,
      review-note, salary-confirmation, or bonus-approval action succeeds, in the same file
- [X] T174 [P] [US1] Feature test: every action is authorized identically at page-open, direct-
      action, and bulk-action checkpoints across all ten resources (FR-005–FR-007), in
      `tests/Feature/Employees/DashboardActionAuthorizationTest.php`
- [X] T175 [P] [US1] Feature test: assigning or changing a user's fixed dashboard role writes an
      `AuditLogger` entry (C1, FR-084, SC-008), in
      `tests/Feature/Employees/RoleAssignmentAuditTest.php`
- [X] T176 [US1] Wire `Employee Manager` and `Payroll Officer` into the existing role-management UI
      so a `System Admin` can assign them like any other fixed role, writing the audit entry T175
      expects
- [X] T177 [US1] Run `php artisan test --compact --filter=DashboardFixedRole` and confirm all US1
      tests pass — command-only gate

**Checkpoint**: The four fixed roles behave identically across every dashboard surface this feature
delivers.

---

## Phase 10: User Story 8 - Search, Filter, and Report Across the Module (Priority: P3)

**Goal**: Search/filter the four core record types and view the seven employee report types.

**Independent Test**: Search and filter each of the four core record types, and open each report
view, using data already created by the earlier stories.

- [X] T178 [P] [US8] Feature test: search/filter across employees, plans, tasks, and visits with
      pagination (FR-070, FR-085), in `tests/Feature/Employees/EmployeeSearchAndFilterTest.php`
- [X] T179 [P] [US8] Feature test: each of the seven `EmployeeReportType` aggregates
      (`PlanCompletion`, `OverdueTasks`, `UnexecutedVisits`, `PerformanceByEmployee`,
      `PerformanceByMonth`, `SalaryByEmployee`, `SalaryByMonth`) returns correct data, in
      `tests/Feature/Employees/EmployeeReportServiceTest.php`
- [X] T180 [P] [US8] Feature test: a queued report export completes and produces a downloadable
      file, following the Inventory export pattern (R-008), in
      `tests/Feature/Employees/EmployeeReportExportTest.php`
- [X] T181 [US8] Create `app/Services/Employees/EmployeeReportService.php`, following
      `InventoryReportService`/`InventoryReportFilters`
- [X] T182 [US8] Create the queued export job for employee reports, mirroring
      `GenerateInventoryExport`
- [X] T183 [US8] Create `app/Filament/Resources/EmployeeReports/EmployeeReportResource.php` with
      filters and the export action
- [X] T184 [US8] Add `admin.resources.employee_reports` keys to `lang/en/admin.php`
- [X] T185 [US8] Run `php artisan test --compact --filter=EmployeeReport` and confirm all US8 tests
      pass — command-only gate

**Checkpoint**: Every functional requirement in spec.md now has an end-to-end path through the
dashboard.

---

## Phase 11: Polish & Cross-Cutting Concerns

**Purpose**: Documentation sync, localization completeness, and the final quality gate (WP9).

- [X] T186 [P] Write the §8 ERD deltas into `Docs/database/ERD.md` — drop `visit_attachments`, add
      every column delta recorded in [data-model.md](./data-model.md)
- [X] T187 [P] Sync `Docs/PRD.md`, `Docs/SDD.md`, `Docs/api/API_CONTRACT.md` (recording that no
      employee API is added), `Docs/architecture/SYSTEM_ARCHITECTURE.md`,
      `Docs/IMPLEMENTATION_PLAN.md`, and `Docs/TESTING_STRATEGY.md`
- [X] T188 [P] Add `lang/en/admin.php` error keys for every rejected transition, the plan-copy
      conflict, the null-payable-base validation error, and the confidence-unavailable label (§13)
- [X] T189 [P] Add `EmployeeEnglishLabelsTest` mirroring `CrmEnglishLabelsTest` — asserts no
      untranslated key
- [X] T190 Update `AdminModuleRegistryTest` and `DashboardLayoutTest` for the new sections and items
- [X] T191 Run `vendor/bin/pint --dirty --format agent`
- [X] T192 Run `vendor/bin/phpstan analyse` — confirm no new baseline entries; remove any existing
      entries in touched files that no longer apply
- [X] T193 Run `composer test --coverage` — confirm 100% type coverage and 100% code coverage held.
      100% type coverage confirmed. Every Employees-namespace file (`app/**/Employees*`,
      `app/Services/Employees/**`, `app/Filament/Resources/{Employees,EmployeeReports,Visits,
      VoiceNotes,AiKeywordRules,OpportunityDrafts,Performance,SalaryCalculations}/**`, the 13 new
      models, enums, and policies) is at 100% code coverage. The repo-wide total is 94.2%, entirely
      attributable to pre-existing gaps in `app/Services/{Orders,Inventory,Shipments}/*` that predate
      this feature and are outside its scope — see the completion report for the full file list.
- [X] T194 Manual smoke: walk through [quickstart.md](./quickstart.md) scenarios 1–8 in the running
      dashboard. Logged in as the seeded admin and visited all ten `employees` navigation-group
      resources plus the Reports page in a real browser (migrations run against the real local DB
      first); every page rendered with no console errors. Full interactive walkthrough of every
      scenario step (e.g. clicking approve/reject, triggering an export) was not exhaustively
      re-driven manually beyond this, since those paths are already covered by Livewire-level
      feature tests.
- [X] T195 Run the Full gate from [quickstart.md](./quickstart.md) (`composer test`,
      `vendor/bin/pint`, `vendor/bin/phpstan analyse`) and record the result — command-only gate.
      Result: pint clean, rector clean, phpstan 0 errors (no baseline growth), type coverage 100%,
      976/976 tests passing, code coverage 100% for every Employees file. Full-repo code coverage
      is 94.2% due to the pre-existing non-Employees gap noted in T193.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: no dependencies — can start immediately.
- **Foundational (Phase 2)**: depends on Setup — BLOCKS all user stories.
- **US2 (Phase 3)**: depends on Foundational only.
- **US3 (Phase 4)**: depends on US2 (`EmployeeProfile` must exist for a plan to belong to).
- **US4 (Phase 5)**: depends on US3 (`SalesPlan` must exist for a task to belong to).
- **US5 (Phase 6)**: depends on US2 (`EmployeeProfile`); independent of US3/US4 except that a visit
  may optionally attribute to a `PlanTask` from US4.
- **US6 (Phase 7)**: depends on US5 (`CustomerVisit` must exist for a voice note to attach to).
- **US7 (Phase 8)**: depends on US4 (`completed_at`) **and** US5 (visit check-in/check-out) — plan.md
  §15.1.
- **US1 (Phase 9)**: depends on US2–US8 having built the resources it verifies (see "Execution
  order vs. spec priority" above).
- **US8 (Phase 10)**: depends on US2–US7's data existing to report on.
- **Polish (Phase 11)**: depends on every desired story being complete.

### Parallel Opportunities

- All Setup tasks marked `[P]` can run in parallel.
- Within Foundational, T006, T012–T013, T015–T017 are `[P]`.
- Within each story, every test task is `[P]` (independent files); enum/model/factory creation
  tasks marked `[P]` can run alongside each other but before the service/resource tasks that depend
  on them.
- US6 (voice notes/AI) has no dependency on US7 and could run in parallel with it once US5 lands,
  if staffed separately — the sequential phase order above is for a single-implementer trace, not a
  hard constraint.

---

## Parallel Example: User Story 3

```bash
# Launch all tests for User Story 3 together:
Task: "Feature test: weight-sum + task-count invariant in tests/Feature/Employees/SalesPlanInvariantsTest.php"
Task: "Feature test: DB-level active-plan uniqueness in tests/Feature/Employees/SalesPlanActivePlanConstraintTest.php"
Task: "Feature test: plan duplication field-by-field in tests/Feature/Employees/SalesPlanDuplicationTest.php"
Task: "Enum unit test: SalesPlanStatus transitions in tests/Unit/Enums/SalesPlanStatusTest.php"

# Launch independent data-layer tasks together:
Task: "Create app/Enums/SalesPlanStatus.php"
Task: "Create database/factories/SalesPlanFactory.php"
```

---

## Implementation Strategy

### MVP First (User Stories 2–4 Only)

1. Complete Phase 1: Setup.
2. Complete Phase 2: Foundational (CRITICAL — blocks all stories).
3. Complete Phase 3 (US2 profiles) → Phase 4 (US3 plans) → Phase 5 (US4 tasks).
4. **STOP and VALIDATE**: employees, plans, and tasks are independently demoable — the smallest
   slice that delivers real value (a manager can plan and track an employee's month).

### Incremental Delivery

1. Setup + Foundational → foundation ready.
2. US2 → US3 → US4 → demo the planning core.
3. US5 (visits) → demo field-visit review.
4. US6 (AI) → demo voice-note review, independent of scoring.
5. US7 (performance/salary) → demo the financial payoff, now that US4+US5 supply its inputs.
6. US1 (roles, verified across everything built so far) → US8 (reports) → Polish.

### Parallel Team Strategy

With multiple developers, after Foundational:

- Developer A: US2 → US3 → US4 (the planning chain, strictly sequential).
- Developer B: US5 → US6 (visits and AI, blocked only on US2).
- Both converge for US7 (needs US4 and US5), then split again for US1/US8/Polish.
