# Implementation Plan: Employees, Monthly Plans, Visits, Performance & Salary Dashboard

**Spec-Kit Feature**: `015-employees-plans-visits-dashboard`

**Suggested Branch**: `015-employees-plans-visits-dashboard`

**Date**: 2026-08-04

**Source SRS**: `IERP_Employees_Module_SRS.pdf` (Arabic, 2 pages) — "لوحة التحكم: الموظفون والخطط والزيارات"

**Status**: PLAN ONLY — no implementation performed. All functional clarifications
are settled (§14). The single remaining prerequisite is the ADR 0003 governance
gate in WP0.

---

## 0. How to drive this with Spec Kit

This plan is written to slot into the repo's existing Spec Kit installation
(`.specify/`, `specs/001..014`). It is the `plan.md` artifact. The surrounding
artifacts do **not** exist yet and must be produced in the order below.

| Step | Command | Produces | Notes |
|---|---|---|---|
| 1 | `/speckit-constitution` | Amended `.specify/memory/constitution.md` (1.3.0 → 1.4.0) | **Blocking.** Records the ADR 0003 dashboard-only exception for the Employees module. See §5. |
| 2 | `/speckit-specify` | `specs/015-…/spec.md` | Translate the Arabic SRS into English user stories + the FR IDs in §6. |
| 3 | `/speckit-clarify` | Updated `spec.md` | Encode the ten settled decisions D1–D10 (§14). No functional question remains open. |
| 4 | `/speckit-plan` | `research.md`, `data-model.md`, `contracts/`, `quickstart.md` | Use §8–§13 of this file as the input. |
| 5 | `/speckit-tasks` | `specs/015-…/tasks.md` | Use the phase/task skeleton in §16. |
| 6 | `/speckit-checklist` | `specs/015-…/checklists/requirements.md` | Mirror `specs/013-…/checklists/requirements.md`. |
| 7 | `/speckit-analyze` | Cross-artifact consistency report | Resolve every critical/high finding before any code. |
| 8 | `/speckit-implement` | Production code | Only after steps 1–7 are green. |
| 9 | `/speckit-converge` | Appended tasks | Use if implementation drifts from the plan. |

**Target artifact set** (mirrors `specs/013-crm-customers-subscriptions/`):

```text
specs/015-employees-plans-visits-dashboard/
├── plan.md                      # this file
├── spec.md                      # step 2
├── research.md                  # step 4
├── data-model.md                # step 4
├── quickstart.md                # step 4
├── contracts/
│   ├── permissions.md           # employees.* catalogue + fixed role matrix
│   ├── performance-scoring.md   # score + salary arithmetic (D2, D3, D4, D5)
│   ├── plan-lifecycle.md        # state machines (D8) + plan-copy rules (D9)
│   ├── voice-note-ai.md         # Whisper driver, confidence contract (D6)
│   └── dashboard-ui.md          # screens, states, actions per resource
├── checklists/
│   └── requirements.md          # step 6
└── tasks.md                     # step 5
```

---

## 1. Summary

Deliver the Employees module of the `/admin` Filament panel: employee profiles
with optional base salary, monthly plans with weighted evaluation factors,
plan tasks with an audited status history, field visits with GPS trails and
attachments, AI voice-note transcription with human-gated sales-opportunity
drafts, weighted performance scoring, salary calculation with admin-approved
bonuses, and the search/report/audit surfaces over all of it.

The module is **greenfield in code but pre-designed in documentation**: all 14
tables already exist in `Docs/database/ERD.md`, and
`app/Filament/AdminModuleRegistry.php` already pins the exact Filament resource
class names and navigation group. Implementation must land inside those
existing contracts, not invent parallel ones.

Five facts shape the whole plan:

1. **A constitution amendment is the one remaining prerequisite.** The
   constitution permits a Filament dashboard for Inventory (ADR 0001) and CRM
   (ADR 0002) only. An Employees dashboard needs ADR 0003, scoped to the
   dashboard alone (**D10**).
2. **The ERD conflicted with Constitution IV on file storage.** `visit_attachments`
   and `employee_voice_notes.audio_path` are custom per-feature file storage,
   which Principle IV prohibits in favour of Spatie Media Library. Resolved by
   **D1**: use Media Library and correct the ERD.
3. **The SRS defined performance twice, incompatibly** — §3.6 asks for a
   weighted four-factor score *and* states performance percent is simply
   completed÷total tasks. Resolved by **D2**: the weighted `total_score` drives
   pay; the task ratio is a displayed statistic.
4. **Schedule and work-time scores come from data this feature already owns**
   (**D5**). No attendance, shift, or working-hours module is added. Schedule
   adherence reads task completion against `due_at`; work-time adherence reads
   visit check-in/check-out spans against a per-plan required duration.
5. **Transcription is OpenAI Whisper behind an abstraction** (**D6**), and the
   API does not return a calibrated confidence value. The plan therefore
   defines an explicit, labelled fallback rather than a fabricated number
   (§12.3).

---

## 2. Verified pre-implementation state

Everything in this section was verified against the working tree on 2026-08-04,
not assumed.

**Nothing is implemented.** No employee-module table, model, migration,
factory, policy, service, enum, or Filament resource exists.

Confirmed absent (all seven are referenced by `AdminModuleRegistry` and
currently fall through to `ModulePlaceholder`):

| Pinned class | Backing model (to create) |
|---|---|
| `App\Filament\Resources\Employees\EmployeeResource` | `EmployeeProfile` |
| `App\Filament\Resources\MonthlyPlans\MonthlyPlanResource` | `SalesPlan` |
| `App\Filament\Resources\Tasks\TaskResource` | `PlanTask` |
| `App\Filament\Resources\Visits\VisitResource` | `CustomerVisit` |
| `App\Filament\Resources\Performance\PerformanceResource` | `EmployeePerformanceScore` |
| `App\Filament\Resources\SalaryCalculations\SalaryCalculationResource` | `EmployeeSalaryCalculation` |
| `App\Filament\Resources\EmployeeReports\EmployeeReportResource` | report aggregate (no table) |

Confirmed already in place and reusable:

- Navigation group `employees` (`AdminModuleRegistry::groups()`, `sort => 6`,
  `Heroicon::OutlinedIdentification`) with all six item labels.
- English labels `admin.groups.employees` and `admin.resources.{employees,
  monthly_plans, visits, tasks, performance, salary_calculations,
  employee_reports}` in `lang/en/admin.php`.
- `audit_logs` table + `App\Services\Audit\AuditLogger` — the single audit
  writer, deliberately transaction-free so callers own the transaction.
- Spatie Permission (`HasRoles` on `User`), `UserType::Employee`.
- Spatie Media Library, used on `CustomerProfile`, `InventoryOperation`,
  `Product`, `ProductVariant`; private `local` disk is the established pattern
  for sensitive documents.
- `TracksBlameable` concern for `created_by`/`updated_by`.
- `PageUsageGuide` derives help text from the class name — new resources need
  no registration there.

Two divergences between the ERD and the actual schema, which this plan resolves
in favour of the code:

- **`users` has no `is_active` and no `deleted_at`.** The ERD claims both.
  Activation state lives on the profile (`customer_profiles.is_active`).
  → Employee activation goes on `employee_profiles.is_active`, matching CRM.
- **Customer FK convention is mixed.** Pricing tables use
  `customer_user_id → users`; the newer Orders/Inventory tables use
  `customer_id → customer_profiles`. → Employee tables follow the newer
  convention: `customer_id → customer_profiles`.

**Baseline quality gate**: `composer test` = `pint --test` + `rector --dry-run`
+ `phpstan` + `pest --type-coverage --min=100` + `artisan test --parallel` +
`pest --coverage --min=100`. Both thresholds are **100%** and per CLAUDE.md
rule 8 must not be lowered. The worktree is currently dirty with unrelated
inventory/CRM changes on `codex/crm-customer-subscriptions`; a fresh branch off
a clean tree is required before WP1.

---

## 3. Technical context

**Language/Version**: PHP 8.4

**Primary Dependencies**: Laravel 13, Filament 5, Livewire 3, Spatie Laravel
Permission 8, Spatie Laravel Media Library, Spatie Laravel Data

**External services**: OpenAI audio transcription (Whisper) — the only external
dependency this feature introduces, reached exclusively through the
`VoiceNoteTranscriber` abstraction (**D6**)

**Storage**: MySQL (production/local via Laragon); SQLite `:memory:` for tests
(`phpunit.xml`) — every migration and index must work on both

**Testing**: Pest 4, PHPUnit 12, PHPStan/Larastan 3 (no new baseline entries),
Pint, Rector, Xdebug coverage. No test may reach the network — the fake
transcription driver is mandatory in the test environment.

**Target Platform**: existing `/admin` Filament panel

**Project Type**: Laravel modular monolith with Filament admin resources

**Constraints**:

- ADR 0003 must be approved before any production code (§5);
- no attendance, shift, or working-hours module (**D5**);
- no employee-facing API or mobile surface (**D10**);
- no second audit trail, permission store, or media store;
- no new `phpstan-baseline.neon` entries; baseline may only shrink;
- 100% type coverage and 100% code coverage must both stay at 100%;
- AI failure must never block visit completion (Principle V);
- no AI output takes effect without a human decision (Principle V);
- no fabricated AI confidence value may be presented as provider-reported (**D6**);
- state transitions are enforced in domain services, never only by hiding a
  Filament button (**D8**);
- English-only UI strings for new features, following spec 013's precedent;
- preserve unrelated dirty worktree changes.

---

## 4. Scope

**In scope** (SRS §1.2, matching the ADR 0003 authorisation in **D10**):
employee profiles, statuses and salary options; monthly plans with evaluation
weights and tasks; tasks and completion tracking; visits with location records,
attachments and review; voice-note review; AI transcription review; performance
calculations; salary calculations; bonus review; employee reports; dashboard
roles and permissions.

**Out of scope for this feature** — and explicitly *not* authorised by ADR 0003
(**D10**):

- `/api/employee` endpoints and any other employee-facing API functionality;
- the employee mobile application;
- employee-app visit capture;
- employee-app attendance capture;
- mobile authentication flows.

Also out of scope: any attendance, shift, or working-hours module (**D5**);
ticket and maintenance modules; quotation/delivery creation during a visit;
payroll disbursement or accounting postings for salaries; automated AI
decisions.

Implementing the employee API later requires its own specification and, where
the constitution requires it, a separate ADR or an explicit amendment to
ADR 0003.

Because visit and GPS capture belong to the out-of-scope employee app, this
feature treats `customer_visits`, `visit_gps_logs` and `employee_voice_notes` as
**read, review and administer** surfaces. The dashboard may create a visit
record (`recorded_channel = dashboard`), but field capture is not built here.

---

## 5. Constitution check

*GATE: must pass before Phase 0 research; re-check after Phase 1 design.*

| Principle | Result | Treatment |
|---|---|---|
| I. Specification-First | **Fail until WP0** | The ERD exists but no feature spec does. The ten §14 decisions and the §8 deltas must be encoded into `spec.md` + `Docs/database/ERD.md` before code. Principle I requires the database design to be *finalized* first; D5–D9 close every schema question, so WP0 can now complete. |
| II. Domain-Driven Modular Monolith | Pass by design | All rules in `app/Services/Employees/*`; Filament resources are adapters only. New folder `app/Services/Employees/` sits beside `Crm`, `Inventory`, `Orders`, `Identity`. Status transitions live on the enums + services, not in the UI (**D8**). |
| III. Financial & Inventory Integrity | Pass — narrow read | No stock or ledger writes. Salary is *calculated*, not posted to accounting. Every plan/task/visit/salary mutation runs in `DB::transaction()`; superseded salary rows are marked, never deleted. |
| IV. Unified Access, Media & Payment | Pass after D1 | The ERD's custom file storage is dropped in favour of Media Library collections (§8.4), so no exception is needed. Authorization uses Spatie Permission. Transcription and exports run as queued jobs. |
| V. AI Isolation & Human Oversight | Pass by design | Transcription is a queued Whisper call behind an interface; failure writes `voice_note_transcriptions.error_message` and never changes visit status, performance, or salary. Drafts and bonus suggestions require a recorded human decision. Confidence is never fabricated (**D6**). |
| VI. Engineering Discipline | Pass when WP8 completes | Thin adapters, typed services, transactions, queues, tests per rule, audit on sensitive actions. |

### 5.1 Governance gate (the one remaining blocker)

> Constitution §Product Scope & Boundaries: "a Filament dashboard dependency
> (**exception approved for the Inventory module only** … a Filament dashboard
> for any other module remains out of scope pending a separate ADR)".
> ADR 0002 extends this to CRM customers and pricing tiers only.

**An Employees Filament dashboard is currently out of scope.** WP0 must
produce `Docs/adr/0003-filament-employees-dashboard.md` with project-owner
approval and amend the constitution to 1.4.0 (MINOR — Product Scope materially
expanded), regenerating the Sync Impact Report at the top of the file.

**ADR 0003 approved scope (D10)** — the ADR text and the constitution amendment
note must both carry these two lists verbatim.

*Authorised:* employee profiles; monthly plans; tasks; visits; voice-note
review; AI transcription review; performance calculations; salary calculations;
bonus review; employee reports; dashboard roles and permissions — all as
`/admin` Filament surfaces.

*Not authorised:* `/api/employee` endpoints; the employee mobile application;
employee-app visit capture; employee-app attendance capture; mobile
authentication flows; any other employee-facing API functionality.

One smaller governance item in the same gate: the constitution's extraction
order names this work `011-employee-app-plans-visits-ai`. Actual directories run
`001`–`014` with different names. WP0 should either reconcile the list or note
the divergence so `015-…` is not treated as skipping a prerequisite. The
amendment should also note that the `-app-` portion of that historical name is
**not** authorised by ADR 0003.

---

## 6. Requirement traceability

FR IDs derived from the SRS. Use these verbatim in `spec.md` and `tasks.md` so
`/speckit-analyze` can cross-check coverage.

### Roles and authorization — SRS §2

| ID | Requirement |
|---|---|
| FR-001 | System Manager role: full management, role management, approving exceptions, restoration |
| FR-002 | Employees Manager role: manage employee profiles, plans, tasks; review visits |
| FR-003 | Payroll Officer role: review performance, calculate salaries, approve bonuses |
| FR-004 | Reviewer role: view data, reports and logs with no edit capability |
| FR-005 | Permission is checked both on page open **and** on every action execution |
| FR-006 | Hiding a button is not sufficient to prevent an action |
| FR-007 | Bulk actions are subject to the same permissions as the individual action |
| FR-008 | Every status transition is validated in a domain service; a rejected transition fails with a clear message even when invoked directly (**D8**) |

### Employee profiles — SRS §3.1

| ID | Requirement |
|---|---|
| FR-010 | Create an employee with a unique code, job title and contact data |
| FR-011 | Calculate salary and monitor tasks to produce the final monthly salary |
| FR-012 | Edit employee data; enable or disable their access to the app |
| FR-013 | Delete an employee account while retaining the data as an archive |
| FR-014 | Search by code or name; filter by status and job title |

### Monthly plans — SRS §3.2

| ID | Requirement |
|---|---|
| FR-020 | Create a monthly plan for an employee. Assigning the same plan to another employee creates an **independent copy** — a new `sales_plans` row owned by the target employee, never a shared plan (**D9**) |
| FR-021 | Set weights for tasks, visits, schedule commitment and work-time commitment |
| FR-022 | Block saving a plan when tasks do not cover 100% of the plan — per **D4**, the four factor weights must sum to exactly 100 and the plan must have ≥1 task |
| FR-023 | Block more than one active plan for the same employee in the same month |
| FR-024 | Copy a previous month's plan, with its tasks, into a new month; task date offsets are rebased onto the target month (**D9**) |
| FR-025 | Edit, deactivate, delete and restore a plan; delete only while no employee has completed any task |
| FR-026 | Set the plan's required visit duration, which work-time adherence is measured against (**D5**) |

### Tasks — SRS §3.3

| ID | Requirement |
|---|---|
| FR-030 | Create a task inside a plan with title, description, start date and end date; both dates are **required** (**D5** — schedule adherence needs a deadline on every task) |
| FR-031 | Link a task to a customer when needed |
| FR-032 | Task dates must fall inside the plan's window — never before or after it |
| FR-033 | Log every task status change with actor, timestamp and note |
| FR-034 | Show overdue, near-due and completed tasks |
| FR-035 | Record the task's completion timestamp when it enters `Completed`, and clear it when the task is reopened (**D5**) |

### Visits and location — SRS §3.4

| ID | Requirement |
|---|---|
| FR-040 | Show an employee's planned and executed visits, linked to task and customer |
| FR-041 | Show check-in time, check-out time, visit duration and results |
| FR-042 | Show the visit's GPS records in chronological order |
| FR-043 | Show visit attachments (images and files) |
| FR-044 | Block editing a field-recorded visit except by admin, while keeping the review-note action available to an authorized reviewer |
| FR-045 | Store exactly one review note per visit — `review_note`, `reviewed_by`, `reviewed_at` on `customer_visits`; every create or update is written to the central audit log (**D7**) |

### Voice notes and AI — SRS §3.5

| ID | Requirement |
|---|---|
| FR-050 | Show voice notes linked to visits and their processing state |
| FR-051 | Show extracted transcript text, confidence as a 0–100 percentage, its source label, and the failure reason when present (**D6**) |
| FR-052 | Manage keyword rules and link them to products and variants |
| FR-053 | Show draft sales opportunities |
| FR-054 | No AI output is approved automatically without an authorized review |
| FR-055 | Transcription supports English, Arabic, mixed Arabic/English, Arabic local dialects, and varied English accents (**D6**) |
| FR-056 | When the provider reports no reliable confidence, store no confidence value and label it as unavailable — never display a fabricated figure as provider-reported (**D6**) |

### Performance and salary — SRS §3.6

| ID | Requirement |
|---|---|
| FR-060 | Calculate task, visit, schedule and work-time scores plus a total score per plan, using only existing task and visit data (**D5**) |
| FR-061 | Show the source of each score and the weights used, on a preview screen |
| FR-062 | Calculate salary from the optional base salary, performance percentage and bonus |
| FR-063 | Show the performance percentage; the completed÷total task ratio is displayed as a statistic while the weighted total drives pay (**D2**) |
| FR-064 | Show bonus suggestions with reasons; approval requires a recorded admin decision |
| FR-065 | When the plan changes, recalculate salary on the new plan and notify the admin of the change before confirmation |
| FR-066 | Schedule adherence = completed tasks finished on or before `due_at` ÷ total completed tasks (**D5**) |
| FR-067 | Work-time adherence = completed visits meeting the required duration ÷ total completed visits, where duration is `checked_out_at − checked_in_at` (**D5**) |

### Search, reports, audit — SRS §3.7

| ID | Requirement |
|---|---|
| FR-070 | Search and filter across employees, plans, tasks and visits |
| FR-071 | Show plan completion percentages, overdue tasks and unexecuted visits |
| FR-072 | Show performance and salaries by employee or by month |

### General — SRS §4

| ID | Requirement |
|---|---|
| FR-080 | Clear validation and error messages |
| FR-081 | Pages and actions protected by permission |
| FR-082 | Sensitive operations run safely, with no partial save |
| FR-083 | Audio files stored and playable |
| FR-084 | An audit trail is maintained and reviewable |
| FR-085 | Search, filtering and pagination in all lists |

---

## 7. Domain model overview

```text
User (user_type = employee)
 └─1:1─ EmployeeProfile ──1:N── SalesPlan (one active per employee per month)
                          │        ├─1:N── PlanTask ──1:N── TaskStatusLog
                          │        │           └─N:1── CustomerProfile (nullable)
                          │        ├─1:1── EmployeePerformanceScore
                          │        └─1:N── EmployeeSalaryCalculation (one current, rest superseded)
                          ├─1:N── CustomerVisit ──1:N── VisitGpsLog
                          │           ├─ media: visit-attachments        (Media Library)
                          │           ├─ review_note / reviewed_by / reviewed_at  (D7)
                          │           └─1:N── EmployeeVoiceNote
                          │                      ├─ media: voice-note-audio (Media Library)
                          │                      └─1:1── VoiceNoteTranscription
                          │                                  └─1:N── SalesOpportunityDraft
                          └─1:N── BonusSuggestion ──N:1── SalesOpportunityDraft (nullable)

AiKeywordRule ──N:1── Product / ProductVariant (both nullable)
```

A plan is owned by exactly one employee. Assigning "the same plan" to a second
employee produces a second independent `SalesPlan` row (**D9**); there is no
shared or many-to-many plan model.

---

## 8. Target data model

13 tables — the ERD's 14 minus `visit_attachments`, which **D1** removes.
Columns are as specified in `Docs/database/ERD.md` §6 unless a **delta** is
listed. Every delta must be written back into the ERD during WP0 (Principle I:
database design finalized before implementation).

Conventions to follow, taken from existing migrations:

- `foreignId(...)->constrained(...)` with an explicit
  `cascadeOnDelete`/`restrictOnDelete`/`nullOnDelete`;
- blameable `created_by`/`updated_by` nullable FKs to `users` +
  `TracksBlameable` on the model;
- `softDeletes()` where the ERD says `deleted_at`;
- money `decimal(15,2)`, scores/weights/percentages `decimal(5,2)`,
  GPS `decimal(10,7)`;
- index every FK, plus `status` and date columns used in filters.

### 8.1 `employee_profiles`

Per ERD: `user_id`, `employee_code` (unique), `job_title`, `use_base_salary`,
`base_salary`, `salary_calculation_mode`, blameable, soft deletes.

**Deltas:**

- **Add `is_active` boolean, default true.** FR-012 needs an app-access toggle
  and `users` has no `is_active` column. This mirrors
  `customer_profiles.is_active`.
- **Add contact columns** `phone`, `email` (both nullable) — FR-010 requires
  contact data and the ERD provides none.
- **Add `commission_target_amount decimal(15,2)` nullable** — the payable base
  for employees on `PerformanceOnly` mode (**D3**, §9.2). Required whenever
  `use_base_salary = false`; validated at the service layer and in the form.
- Unique on `employee_code` must be checked with `withTrashed()` when
  generating codes, exactly as `CustomerOnboardingService::generateCustomerCode()`
  does, so archived employees never collide.

`salary_calculation_mode` becomes a `SalaryCalculationMode` enum cast.

### 8.2 `sales_plans`

Per ERD: `employee_id`, `name`, `month` (date), four weights, `status`,
blameable, soft deletes.

**Deltas:**

- `status` cast to `SalesPlanStatus`.
- **Unique-active-plan enforcement (FR-023).** MySQL has no filtered unique
  index, and tests run on SQLite. Approach: a nullable stored column
  `active_month` holding `month` when `status = Active` and `NULL` otherwise,
  with `unique(['employee_id', 'active_month'])` — MySQL and SQLite both treat
  NULLs as distinct, so inactive/draft plans never collide. The service layer
  maintains the column inside the same transaction; a feature test asserts the
  DB rejects a duplicate even when the service is bypassed.
- **Add `required_visit_minutes` unsigned smallint, nullable (D5).** The
  threshold that work-time adherence is measured against (§9.1). It lives on
  the plan because the plan already owns `work_time_weight` — the weight and the
  thing it measures belong together — and because it must be tunable per
  employee per month without touching global config. When null, the service
  falls back to `config('employees.default_required_visit_minutes')`. This is
  **not** an attendance module: it is one integer on a table this feature
  already creates.
- Index `month` and `(employee_id, month)` for FR-072 reporting.
- `month` is normalized to the first day of the month on write.

### 8.3 `plan_tasks` and `task_status_logs`

Per ERD. `plan_tasks.customer_id` → `customer_profiles` (see §2 divergence),
nullable, `nullOnDelete`.

**Deltas:**

- `status` cast to `PlanTaskStatus`.
- **`starts_at` and `due_at` become NOT NULL** (the ERD has them nullable).
  FR-030 already requires both dates, and **D5** makes `due_at` load-bearing:
  schedule adherence divides by *total completed tasks*, so a task with no
  deadline would silently fall out of the numerator and penalise the employee
  for missing admin data. Requiring the deadline removes that failure mode
  instead of special-casing it.
- **Add `completed_at` timestamp, nullable (D5).** The authoritative completion
  timestamp for schedule adherence. Written by `PlanTaskService` in the same
  transaction as the status transition and its `TaskStatusLog` row; cleared
  when a task is reopened (`Completed → InProgress`). A test asserts
  `completed_at` always agrees with the latest `Completed` transition in
  `task_status_logs`, so the denormalised column can never drift from the audit
  trail.
- **No per-task `weight` column.** Resolved by **D4**: FR-022 is a plan-level
  rule (the four factor weights sum to 100), not a per-task allocation.
- `task_status_logs` is append-only: no `updated_at` semantics, no soft delete,
  no update path. Enforce with a model-level guard and a test.
- Index `(sales_plan_id, status)`, `due_at`, and `completed_at` for the
  overdue/near-due views (FR-034) and scoring queries.

### 8.4 Visit attachments and voice-note audio — Principle IV resolution (D1)

The ERD's `visit_attachments` table and `employee_voice_notes.audio_path`
column contradict Constitution IV ("All uploaded files … voice-note audio MUST
use Spatie Laravel Media Library; custom per-feature file tables MUST NOT be
created unless a concrete future requirement proves they are needed").

**Resolution: follow the constitution and correct the ERD.**

- **Drop `visit_attachments` entirely.** Register a `visit-attachments`
  media collection on `CustomerVisit` (private `local` disk), following
  `CustomerProfile::registerMediaCollections()`. The ERD's `notes` column has
  no requirement behind it; media `custom_properties` covers it if needed.
- **Keep `employee_voice_notes`** as a real domain row — it carries
  `employee_id`, `duration_seconds`, `language`, `status` and the transcription
  relation, which are domain data, not file metadata. **Replace `audio_path`
  with a single-file `voice-note-audio` media collection** on the private disk.
- FR-083 (playable audio) is then served by a Filament audio player fed from a
  temporary signed URL, not a public path.

Update `Docs/database/ERD.md` accordingly in WP0.

### 8.5 `customer_visits` and `visit_gps_logs`

Per ERD, with `customer_id` → `customer_profiles`.

**Deltas:**

- `status` cast to `VisitStatus`.
- **Add `recorded_channel` (`VisitRecordChannel` enum: `dashboard` | `field`).**
  FR-044 makes field-recorded visits immutable except for admin, and the ERD
  gives no way to tell the two apart. Default `dashboard`. The `field` value is
  written by the employee app, which **D10** places outside this feature — the
  dashboard reads and honours the flag but never sets it.
- **Add `review_note` text nullable, `reviewed_by` nullable FK → `users`
  (`nullOnDelete`), `reviewed_at` timestamp nullable (D7).** Exactly one review
  note per visit. **No `visit_review_notes` table.** Overwriting the note does
  not lose history: `AuditLogger` records `old_values`/`new_values` on every
  create and update, so the central audit trail is the note's revision history
  (FR-045, FR-084).
- `duration_minutes` is **derived**, never stored — computed from
  `checked_in_at`/`checked_out_at` (FR-041) and reused by work-time adherence
  (§9.1).
- `visit_gps_logs` is append-only, ordered by `recorded_at`; index
  `(customer_visit_id, recorded_at)` for FR-042.
- **Plan attribution.** `customer_visits` has no `sales_plan_id`; a visit
  reaches a plan only through `plan_task_id → plan_tasks.sales_plan_id`, and
  `plan_task_id` is nullable. Index `(plan_task_id, status)` and
  `(employee_id, status)` so **D5** scoring can aggregate a plan's completed
  visits without a full scan. The attribution rule itself is in §9.1.

### 8.6 `employee_voice_notes` and `voice_note_transcriptions`

Per ERD, minus `audio_path` (§8.4). `employee_voice_notes.status` casts to
`VoiceNoteStatus`; `voice_note_transcriptions.status` casts to
`TranscriptionStatus`.

**Deltas on `voice_note_transcriptions` (D6):**

- **`confidence decimal(5,2)` nullable, constrained to `0.00 <= confidence <=
  100.00`.** Stored as a percentage — `0.00`, `87.50`, `100.00`. Validated in
  the model/service and tested at both boundaries. `NULL` means "no confidence
  available", which is semantically different from `0.00` ("zero confidence")
  and must never be collapsed into it.
- **Add `confidence_source` (`TranscriptionConfidenceSource` enum:
  `provider_reported` | `derived_from_log_prob` | `unavailable`).** Whisper does
  not return a calibrated confidence score (§12.3), so the stored number's
  provenance must travel with it. Invariant: `confidence` is non-null when the
  source is `ProviderReported` or `DerivedFromLogProb`, and null when it is
  `Unavailable`. Enforced in the service and tested.
- **Add `detected_language` varchar(20) nullable** — the language Whisper
  actually detected, which for mixed-language recordings can differ from
  `employee_voice_notes.language` (FR-055).
- `provider` records the concrete driver identity (e.g. `openai.whisper-1`) so
  a later provider change is visible in historical rows.
- `error_message` carries the provider-side failure reason (FR-051).

### 8.7 `ai_keyword_rules`

Per ERD. Both `product_id` and `product_variant_id` nullable — a rule with
neither is a pure text match, which is valid; document that explicitly rather
than adding a check constraint, since FR-052 does not require a product link.
Index `is_active` and `keyword`.

### 8.8 `sales_opportunity_drafts`

Per ERD. **Delta:** add `reviewed_by`, `reviewed_at`, `review_notes` so FR-054
("no automatic approval without authorized review") is provable from the row
itself, not only from `audit_logs`. `status` casts to `OpportunityDraftStatus`.

### 8.9 `employee_performance_scores`

Per ERD: four component scores + `total_score`, keyed by plan and employee.

**Deltas:**

- **Add `calculation_breakdown` json.** FR-061 requires showing the *source* of
  each score and the weights used. The stored breakdown makes the preview screen
  a read of recorded facts rather than a recomputation that can drift. Per
  factor it records: numerator, denominator, ratio, weight, weighted
  contribution, and — for the two **D5** factors — the inputs that produced
  them, namely the effective `required_visit_minutes` and the count of completed
  visits excluded for missing timestamps. Snapshotting the threshold means a
  later plan or config change cannot silently alter a historical score.
- **Add `task_completion_percent decimal(5,2)`.** FR-063 still requires the
  completed÷total task ratio to be *displayed*, even though **D2** makes
  `total_score` the figure that drives pay. Storing it explicitly keeps the two
  numbers distinguishable on screen and in reports.
- **Add `calculated_at` timestamp** and unique `(sales_plan_id, employee_id)`.

### 8.10 `employee_salary_calculations`

Per ERD. **Deltas:**

- `status` casts to `SalaryCalculationStatus`.
- **Add `superseded_at` / `superseded_by_id`.** FR-065 recalculates on plan
  change; the previous calculation must be retained, marked, and never deleted.
- **Add `confirmed_by` / `confirmed_at`** for the admin confirmation FR-065
  requires before the new figure takes effect.
- **Add `payable_base decimal(15,2)`** — the resolved base the percentage was
  applied to, copied from `base_salary` or `commission_target_amount` at
  calculation time (§9.2). Recording it keeps a historical salary reproducible
  after the employee profile changes. The ERD's existing `base_salary` column
  stays as the snapshot of the profile value.
- `payable_base`, `performance_percent`, `bonus_amount`, `final_salary` are
  written by the service only — never mass-assignable from a form.

### 8.11 `bonus_suggestions`

Per ERD. **Deltas:** add `approved_by`, `approved_at`, `decision_notes`;
`status` casts to `BonusSuggestionStatus`. FR-064 requires the approval to be
*recorded*, so the decision must be a column, plus an `audit_logs` entry.

### 8.12 New enums (`app/Enums/`) — approved vocabularies (D8)

13 enums. These case sets are **approved**, not proposals. Follow the existing
pattern: `declare(strict_types=1)`, backed string enum, TitleCase cases,
`values(): array` helper where a catalogue is needed.

| Enum | Cases |
|---|---|
| `EmployeePermission` | see §10 |
| `SalesPlanStatus` | `Draft`, `Active`, `Paused`, `Completed`, `Archived` |
| `PlanTaskStatus` | `Pending`, `InProgress`, `Completed`, `Cancelled` |
| `VisitStatus` | `Planned`, `InProgress`, `Completed`, `Missed` |
| `VisitRecordChannel` | `Dashboard`, `Field` |
| `VoiceNoteStatus` | `Pending`, `Processing`, `Transcribed`, `Failed` |
| `TranscriptionStatus` | `Pending`, `Succeeded`, `Failed` |
| `TranscriptionConfidenceSource` | `ProviderReported`, `DerivedFromLogProb`, `Unavailable` |
| `OpportunityDraftStatus` | `Draft`, `Approved`, `Rejected` |
| `BonusSuggestionStatus` | `Pending`, `Approved`, `Rejected` |
| `SalaryCalculationStatus` | `Draft`, `PendingConfirmation`, `Confirmed`, `Superseded` |
| `SalaryCalculationMode` | `PerformanceOnly`, `BasePlusPerformance` |
| `EmployeeReportType` | `PlanCompletion`, `OverdueTasks`, `UnexecutedVisits`, `PerformanceByEmployee`, `PerformanceByMonth`, `SalaryByEmployee`, `SalaryByMonth` |

`TranscriptionConfidenceSource` is the one addition beyond the approved list; it
exists solely to satisfy **D6**'s prohibition on presenting a derived value as
provider-reported.

### 8.13 State transitions (D8)

Each status enum carries `allowedTransitions(): array` and
`canTransitionTo(self $to): bool`. Every domain service calls the guard before
writing, and a rejected transition throws
`App\Services\Employees\Exceptions\InvalidStatusTransition`, which Filament
renders as a clear validation message (FR-008, FR-080). Keeping the rule on the
enum makes it unit-testable without the database and impossible to bypass by
reaching an action directly.

**Self-transitions are rejected everywhere** (a no-op status change is a bug,
not a valid write). Terminal states accept nothing.

**`SalesPlanStatus`**

| From | Allowed to | Guard |
|---|---|---|
| `Draft` | `Active`, `Archived` | → `Active` requires weights = 100, ≥1 task, and no other `Active` plan for that employee/month |
| `Active` | `Paused`, `Completed` | |
| `Paused` | `Active`, `Archived` | → `Active` re-runs the one-active-plan guard |
| `Completed` | `Archived` | |
| `Archived` | — | terminal; soft-delete restore returns a plan to `Archived`, never to `Active` |

Rejected: `Draft → Paused`, `Draft → Completed`, `Active → Draft`,
`Completed → Active`, `Completed → Paused`, anything from `Archived`.

**`PlanTaskStatus`**

| From | Allowed to | Guard |
|---|---|---|
| `Pending` | `InProgress`, `Completed`, `Cancelled` | |
| `InProgress` | `Completed`, `Cancelled`, `Pending` | |
| `Completed` | `InProgress` | reopen; requires `employees.task.manage`, clears `completed_at`, and marks the plan's performance score stale |
| `Cancelled` | `Pending` | reinstate |

Rejected: `Completed → Cancelled`, `Cancelled → Completed`,
`Cancelled → InProgress`.

**`VisitStatus`**

| From | Allowed to | Guard |
|---|---|---|
| `Planned` | `InProgress`, `Missed` | |
| `InProgress` | `Completed`, `Missed` | → `Completed` requires `checked_out_at` |
| `Completed` | — | terminal |
| `Missed` | `Planned` | reschedule |

Rejected: `Planned → Completed` (must check in first), anything from
`Completed`, `Missed → InProgress`, `Missed → Completed`.

**`VoiceNoteStatus`**

| From | Allowed to | Guard |
|---|---|---|
| `Pending` | `Processing` | job picked up |
| `Processing` | `Transcribed`, `Failed` | |
| `Failed` | `Pending` | manual retry, bounded by §12.2 |

Rejected: `Pending → Transcribed`, `Processing → Pending`, anything from
`Transcribed`.

**`TranscriptionStatus`**

| From | Allowed to |
|---|---|
| `Pending` | `Succeeded`, `Failed` |
| `Failed` | `Pending` (retry) |
| `Succeeded` | — terminal |

**`OpportunityDraftStatus`**

| From | Allowed to | Guard |
|---|---|---|
| `Draft` | `Approved`, `Rejected` | requires `employees.opportunity.review` and a recorded `reviewed_by`/`reviewed_at` |

`Approved` and `Rejected` are terminal — a superseded decision means creating a
new draft, so no decision is ever silently rewritten (FR-054).

**`BonusSuggestionStatus`**

| From | Allowed to | Guard |
|---|---|---|
| `Pending` | `Approved`, `Rejected` | requires `employees.bonus.approve` and a recorded `approved_by`/`approved_at` |

`Approved` and `Rejected` are terminal (FR-064).

**`SalaryCalculationStatus`**

| From | Allowed to | Guard |
|---|---|---|
| `Draft` | `PendingConfirmation` | |
| `PendingConfirmation` | `Confirmed`, `Superseded` | → `Confirmed` requires `employees.salary.confirm` |
| `Confirmed` | `Superseded` | only via a recalculation that sets `superseded_by_id` |
| `Superseded` | — | terminal |

Rejected: `Draft → Confirmed`, `Confirmed → Draft`,
`Confirmed → PendingConfirmation`, anything from `Superseded`.

---

## 9. Domain services and contracts

New folder `app/Services/Employees/`. Every mutation runs inside
`DB::transaction()` (FR-082) and calls `AuditLogger` **inside** that transaction
so a rollback discards the audit row too. Every status write goes through the
§8.13 guard.

| Service | Responsibility | Key invariants |
|---|---|---|
| `EmployeeOnboardingService` | Create the `User` + `EmployeeProfile` pair; generate a unique `employee_code` | FR-010; code uniqueness checked `withTrashed()`; `user_type = Employee` |
| `EmployeeAccessService` | Enable/disable app access; archive (soft delete) | FR-012, FR-013; archiving never hard-deletes |
| `SalesPlanService` | Create, update, transition, delete, restore | FR-020–FR-026; maintains `active_month`; refuses delete once any task is completed; every status change passes `SalesPlanStatus::canTransitionTo()` |
| `SalesPlanDuplicationService` | Copy a plan + tasks into a new month and/or onto another employee | FR-020, FR-024; **D9** copy/no-copy lists (§9.3); rejects when the target already has an `Active` plan that month |
| `PlanTaskService` | Create/update tasks; transition status | FR-030–FR-035; dates required and clamped to the plan month; writes one `TaskStatusLog` per transition; sets/clears `completed_at` |
| `VisitReviewService` | Create or update the single review note; gate edits on field-recorded visits | FR-044, FR-045; requires `employees.visit.review`; works on a locked visit; audits `old_values`/`new_values` (**D7**) |
| `VoiceNoteIntakeService` | Attach audio, create the pending transcription row, dispatch the job | Principle V: never blocks the visit; enforces the max-bytes guard before dispatch |
| `TranscribeVoiceNoteJob` (queued) | Call `VoiceNoteTranscriber`, persist transcript/confidence/source/language/error | Bounded retries; failure writes `error_message` + `Failed`, leaves visit, scores and salary untouched |
| `VoiceNoteTranscriber` (interface) | Provider boundary | Implemented by `OpenAiWhisperTranscriber` and `FakeVoiceNoteTranscriber`; no other class may reference the OpenAI client (**D6**, §12) |
| `KeywordDetectionService` | Match active `AiKeywordRule`s against a transcript, create drafts | FR-052, FR-053; drafts always start `Draft` |
| `OpportunityReviewService` | Approve/reject drafts with a recorded decision | FR-054; terminal decisions |
| `PerformanceScoringService` | Compute the four component scores, weighted total, and `calculation_breakdown` | FR-060, FR-061, FR-066, FR-067; pure and deterministic — unit-testable without the DB |
| `SalaryCalculationService` | Resolve `payable_base`, compute `performance_percent`, `bonus_amount`, `final_salary` | FR-062, FR-063 |
| `SalaryRecalculationService` | On plan change: build the new calculation as `PendingConfirmation`, notify admin, supersede the old one on confirmation | FR-065; the old row is marked, never deleted |
| `BonusApprovalService` | Approve/reject bonus suggestions | FR-064; terminal decisions |
| `EmployeeReportService` | Aggregate the seven report types | FR-071, FR-072; follow `InventoryReportService` |

### 9.1 Scoring contract — settled (D2, D3, D4, D5)

Write this into `contracts/performance-scoring.md` before coding.

```text
task_completion      = completed tasks / total tasks in plan            (0..1)
visit_completion     = completed visits / planned visits in plan        (0..1)

# D5 — schedule adherence, from existing task data only
schedule_adherence   = completed tasks with completed_at <= due_at
                       / total completed tasks                          (0..1)

# D5 — work-time adherence, from existing visit data only
actual_visit_duration = checked_out_at - checked_in_at
required_duration     = plan.required_visit_minutes
                        ?? config('employees.default_required_visit_minutes')
work_time_adherence  = completed visits with actual_visit_duration >= required_duration
                       / total completed visits                         (0..1)

task_score       = task_completion      × plan.task_weight
visit_score      = visit_completion     × plan.visit_weight
schedule_score   = schedule_adherence   × plan.schedule_weight
work_time_score  = work_time_adherence  × plan.work_time_weight
total_score      = sum of the four                                      (0..100)

# D4: plan.task_weight + visit_weight + schedule_weight + work_time_weight
#     must equal exactly 100, so total_score is always a 0..100 percentage.

# D2: total_score is the figure that drives pay.
performance_percent      = total_score
# FR-063 statistic, displayed but not payable:
task_completion_percent  = task_completion × 100
```

Worked example for schedule adherence (from the approved decision): an employee
completed 10 tasks and 8 of them were completed on or before their `due_at` →
`schedule_adherence = 0.80`. With `schedule_weight = 10`, `schedule_score = 8.00`.

**Definitions and edge cases** — all of these must be covered by tests:

- *On time* means `completed_at <= due_at`, inclusive. Equality is on time.
- The schedule denominator is tasks in `Completed` status. `Cancelled`,
  `Pending` and `InProgress` tasks are outside both numerator and denominator —
  schedule adherence measures how punctually finished work was finished, not
  how much was finished (that is `task_completion`).
- `due_at` is NOT NULL (§8.3), so every completed task has a deadline to be
  judged against and none can drop out of the numerator for missing data.
- Reopening a task clears `completed_at` and removes it from the schedule
  denominator until it is completed again.
- **Visit-to-plan attribution.** A visit belongs to a plan only through
  `plan_task_id → plan_tasks.sales_plan_id` (§8.5). A visit with a null
  `plan_task_id` is an ad-hoc visit that belongs to no plan, and is therefore
  excluded from both the numerator and the denominator of `visit_completion` and
  `work_time_adherence`. The count of such unattributed visits in the plan's
  month is recorded in `calculation_breakdown`, so the preview screen can show
  that work happened outside the plan rather than silently ignoring it.
- The work-time denominator is plan-attributed visits in `Completed` status. A
  completed visit missing `checked_in_at` or `checked_out_at` **counts in the
  denominator but not the numerator** — its duration cannot be verified, so it
  cannot be credited. The count of such visits is recorded in
  `calculation_breakdown` so the preview screen can explain the gap rather than
  appear to lose points for no reason.
- The effective `required_duration` is snapshotted into
  `calculation_breakdown`; a later plan edit or config change never alters a
  historical score.
- Both **D5** factors read only tables this feature already owns. No
  attendance, shift, or working-hours table is introduced.

**Zero-denominator rule** (applies to all four factors): when a denominator is
0 — a plan with no visits, no completed tasks to judge punctuality against, no
completed visits to judge duration against — that factor scores **0**, its
weight is **not** redistributed to the other factors, and the zero denominator
is recorded explicitly in `calculation_breakdown`. Never divide by zero. Cover
each of the four factors with its own zero-denominator test case.

### 9.2 Salary contract — settled (D2, D3)

```text
payable_base =
    use_base_salary ? employee.base_salary                # BasePlusPerformance
                    : employee.commission_target_amount   # PerformanceOnly (D3)

final_salary = payable_base × (performance_percent / 100) + bonus_amount
             = payable_base × (total_score        / 100) + bonus_amount
```

Both modes share one formula and differ only in which column supplies
`payable_base` — no divergent code path per mode.

Rules:

- `payable_base` is **required**: `base_salary` must be non-null when
  `use_base_salary = true`, and `commission_target_amount` must be non-null when
  it is false. A null payable base is a validation failure, never a silent 0.
- `bonus_amount` is the sum of `bonus_suggestions` in `Approved` state for that
  employee and plan. `Pending` and `Rejected` suggestions contribute nothing.
- Rounding: compute in `decimal(15,2)`, round half-up once at the end, never on
  intermediate factors.
- The row records `payable_base`, `performance_percent`, `bonus_amount` and
  `final_salary` so the figure is reproducible from the row alone, without
  re-reading the employee profile (which may change later).

### 9.3 Plan copy contract — settled (D9)

`SalesPlanDuplicationService` serves both FR-024 (copy to a new month) and
FR-020 (assign to another employee). It creates **one new independent
`sales_plans` row**; there is no shared or many-to-many plan model.

**Copied:** plan name; target month; the four factor weights;
`required_visit_minutes` and any other plan-level configuration; plan tasks with
their titles and descriptions; customer associations where the customer is still
active; task date offsets rebased onto the target month.

**Not copied:** task execution statuses (every copied task starts `Pending`);
`completed_at`; task status history; visit execution records; performance
calculations; salary calculations; bonus decisions; audit records.

**The copy owns its own** `employee_id`, `status`, tasks, performance score and
salary calculations.

Rules:

- The new plan is created as `Draft`, so activation runs the one-active-plan
  guard as a second line of defence (§8.13).
- **Reject the operation** when the target employee already has an `Active` plan
  for the target month, with a clear validation message naming the conflicting
  plan (FR-023, FR-080). This check runs before any row is written.
- Date rebasing: each task's offset from the *source* month's first day is
  applied to the *target* month's first day. When the target month is shorter,
  the resulting date is **clamped to the target month's last day** — a task due
  on 31 January copied into February lands on 28 (or 29) February, never spills
  into March. Both `starts_at` and `due_at` are rebased, and the result must
  still satisfy FR-032 (inside the plan window). Month-length clamping needs its
  own test.
- The whole copy runs in one transaction: either the plan and all its tasks
  exist, or none do (FR-082).
- The copy is audited as a single `plan.copied` entry recording source plan,
  target employee and target month.

---

## 10. Authorization

New `app/Enums/EmployeePermission.php` (guard `web`), mirroring
`InventoryPermission`/`CrmPermission`.

```text
employees.employee.view          employees.employee.manage        employees.employee.restore
employees.plan.view              employees.plan.manage            employees.plan.restore
employees.task.view              employees.task.manage
employees.visit.view             employees.visit.review           employees.visit.field-edit
employees.voice-note.view        employees.voice-note.play
employees.ai-rule.view           employees.ai-rule.manage
employees.opportunity.view       employees.opportunity.review
employees.performance.view       employees.performance.recalculate
employees.salary.view            employees.salary.calculate       employees.salary.confirm
employees.bonus.view             employees.bonus.approve
employees.report.view            employees.audit.view
```

Two abilities carry the **D7** and FR-044 split, and must not be conflated:

- `employees.visit.field-edit` is the admin-only escape hatch that permits
  editing a `field`-recorded visit. Granted to `System Admin` only.
- `employees.visit.review` permits creating or updating the single review note.
  It remains usable on a locked field-recorded visit, which is the entire point
  of FR-044 — the record stays immutable while review stays possible.

### 10.1 Fixed roles (SRS §2)

| SRS role | Role name | Permissions |
|---|---|---|
| مدير النظام | `System Admin` *(existing)* | all of the above |
| مدير الموظفين | `Employee Manager` *(new)* | employee/plan/task view+manage, visit view+review, voice-note view+play, ai-rule view, opportunity view, performance view, report view, audit view |
| مسؤول الرواتب | `Payroll Officer` *(new)* | employee view, plan view, performance view+recalculate, salary view+calculate+confirm, bonus view+approve, report view, audit view |
| المراجع | `Reviewer` *(existing)* | every `.view` permission; nothing else — no `visit.review`, no `salary.confirm`, no `bonus.approve` |

New `database/seeders/EmployeePermissionSeeder.php` following
`CrmPermissionSeeder`: `forgetCachedPermissions()`, `Permission::findOrCreate`,
`Role::findOrCreate(...)->givePermissionTo(...)`.

### 10.2 Cross-module fixed-role leak — must be fixed, not worked around

`ChecksCrmPermissions::authorizeCrmAbility()` currently reads:

```php
if ($user->isAdmin() && ! $user->hasAnyRole(CrmPermission::fixedRoleNames())) {
    return true;
}
```

`CrmPermission::fixedRoleNames()` returns
`['System Admin', 'CRM Manager', 'Pricing Manager', 'Reviewer']`. An admin
whose only role is the **new** `Payroll Officer` or `Employee Manager` is not in
that list, so the bypass fires and grants them **full CRM access** — including
customer management and pricing tiers. The same hazard exists in
`ChecksInventoryPermissions`.

Resolution: introduce a single source of truth for fixed dashboard role names
(e.g. `App\Enums\DashboardRole` or a `FixedDashboardRoles` value object) that
all three traits consult, so adding a role to any module automatically narrows
the bypass everywhere. This is a small, targeted change to existing code —
justified because the new roles would otherwise silently widen CRM and
Inventory access. Regression tests must cover "admin holding only
`Payroll Officer` cannot manage CRM customers" and the same for
`Employee Manager`.

### 10.3 Policies

New `app/Policies/Concerns/ChecksEmployeePermissions.php` plus one policy per
model: `EmployeeProfilePolicy`, `SalesPlanPolicy`, `PlanTaskPolicy`,
`CustomerVisitPolicy`, `EmployeeVoiceNotePolicy`, `AiKeywordRulePolicy`,
`SalesOpportunityDraftPolicy`, `EmployeePerformanceScorePolicy`,
`EmployeeSalaryCalculationPolicy`, `BonusSuggestionPolicy`. Each exposes an
`employeePermissionMap()` and returns `false` from `forceDelete()`, matching
`CustomerProfilePolicy`.

FR-005/FR-006/FR-007/FR-008 require tests that assert:

- every resource page 403s without the view permission;
- every action (including `Action`s reached directly, not via a visible button)
  is authorized server-side;
- every `BulkAction` authorizes each record with the same ability as the single
  action;
- every rejected state transition (§8.13) fails in the service even when the UI
  is bypassed entirely.

---

## 11. Dashboard surface

Class names for the six pinned resources are **fixed** by
`AdminModuleRegistry`. Navigation sort belongs to the `employees` range
**600–699** (group `sort => 6`, per the registry's documented "group position ×
100" convention).

| Resource | Model | Sort | Pages |
|---|---|---|---|
| `Employees\EmployeeResource` | `EmployeeProfile` | 601 | List, Create, View, Edit |
| `MonthlyPlans\MonthlyPlanResource` | `SalesPlan` | 611 | List, Create, View, Edit |
| `Tasks\TaskResource` | `PlanTask` | 612 | List, View, Edit |
| `Visits\VisitResource` | `CustomerVisit` | 621 | List, View (+ Edit for `visit.field-edit` holders) |
| `Performance\PerformanceResource` | `EmployeePerformanceScore` | 641 | List, View (preview screen) |
| `SalaryCalculations\SalaryCalculationResource` | `EmployeeSalaryCalculation` | 642 | List, View |
| `EmployeeReports\EmployeeReportResource` | report aggregate | reports group | List with filters + export |

Each follows the established layout:
`{Domain}/{Name}Resource.php`, `{Domain}/Pages/*.php`,
`{Domain}/Schemas/{Name}Form.php`, `{Domain}/Schemas/{Name}Infolist.php`,
`{Domain}/Tables/{Name}sTable.php`, with `#[\Override]` on the inherited
statics and `navigationGroup = 'admin.groups.employees'`.

### 11.1 Registry additions required

Four surfaces have no registry entry: voice notes, AI keyword rules,
sales-opportunity drafts, and bonus suggestions. The `employees` group has 6
items and needs 10.

**Recommendation:** give the `employees` group `sections`, exactly as the
`inventory` group does (the registry already supports this and
`AdminPanelServiceProvider::navigation()` renders sections as collapsible
`NavigationGroup`s):

| Section key | Label key | Items |
|---|---|---|
| `workforce` | `admin.sections.workforce` | Employees |
| `planning` | `admin.sections.planning` | Monthly Plans, Tasks |
| `field` | `admin.sections.field` | Visits, Voice Notes |
| `intelligence` | `admin.sections.intelligence` | Keyword Rules, Opportunity Drafts |
| `compensation` | `admin.sections.compensation` | Performance, Salary Calculations, Bonus Suggestions |

This requires new `admin.sections.*` and `admin.resources.*` keys in
`lang/en/admin.php` (+ `lang/ar/admin.php`) and matching registry items. Note
that `AdminModuleRegistryTest` and `DashboardLayoutTest` already exist and will
need updating.

### 11.2 Notable screen behaviours

- **Plan form** — weight fields with a live sum indicator; save blocked unless
  the FR-022 rule passes, with the failure explained inline (FR-080). A
  `required_visit_minutes` field with the config default shown as the
  placeholder, labelled as the threshold work-time adherence measures against
  (FR-026).
- **Plan actions** — "Copy to month" and "Assign to another employee" both open
  the **D9** duplication flow, which names the target employee and month and
  refuses up front when that employee already has an active plan for it. Plus
  Activate/Pause/Complete/Archive (each gated by §8.13), Delete (disabled with a
  reason once a task is completed), Restore.
- **Task form** — `starts_at` and `due_at` are required and validated against
  the plan window (FR-030, FR-032).
- **Task table** — filters for Overdue / Due soon / Completed (FR-034);
  status-change action that always writes a `TaskStatusLog` with a note, and
  refuses a rejected transition with a clear message rather than a hidden
  button. Reopening a `Completed` task warns that it clears `completed_at` and
  makes the plan's performance score stale.
- **Visit view** — infolist with computed duration, a chronological GPS
  timeline (`RepeatableEntry` over `visit_gps_logs`), an attachments gallery
  from the media collection, and an audio player per voice note. The whole
  form is read-only when `recorded_channel = field` and the user lacks
  `employees.visit.field-edit`.
- **Visit review note (D7)** — a single "Add / update review note" action,
  visible to `employees.visit.review` holders and **available even while the
  visit is locked**. The infolist shows the current note with `reviewed_by` and
  `reviewed_at`; the audit log link exposes prior versions, since one column
  holds only the latest text.
- **Voice note panel** — processing status, transcript, `detected_language`, and
  `error_message` shown as a warning when transcription failed (FR-051).
  Confidence rendering is governed by `confidence_source` (**D6**):
  `ProviderReported` → `87.50%`; `DerivedFromLogProb` → `≈ 87.50%` with a
  tooltip stating it is derived from model log-probabilities, not a
  provider-reported confidence; `Unavailable` → "Not reported by provider".
  A null confidence must never render as `0.00%` (FR-056).
- **Performance view** — the FR-061 preview: each factor's numerator,
  denominator, ratio, weight and weighted contribution, read from
  `calculation_breakdown`, including the effective `required_visit_minutes` and
  the count of completed visits excluded for missing timestamps, plus an
  explicit "no data" marker on any zero-denominator factor.
- **Salary view** — a `PendingConfirmation` banner when a plan change has
  produced a new calculation, with an explicit Confirm action (FR-065) and a
  link to the superseded row. `payable_base` is shown alongside its source mode.
- **Every table** — searchable, filterable, paginated (FR-085, FR-014, FR-070).

---

## 12. AI isolation design (Principle V, D6)

### 12.1 Flow

```text
Visit completed ──> VoiceNoteIntakeService
                      ├─ store audio in the private media collection
                      ├─ reject oversized audio up front (max-bytes guard)
                      ├─ create voice_note_transcriptions row (Pending)
                      └─ dispatch TranscribeVoiceNoteJob  ── queue ──┐
                                                                      │
Visit status is already final; nothing below can change it. <─────────┘
                      │
                      ├─ success: transcript, confidence + confidence_source,
                      │           detected_language, status Succeeded
                      │             └─ KeywordDetectionService → drafts (Draft)
                      └─ failure: error_message, status Failed, retries bounded
```

A test must assert that a throwing transcriber leaves visit status, performance
scores and salary untouched. Drafts and bonus suggestions never reach
`Approved` without a recorded human decision (FR-054, FR-064).

### 12.2 Provider boundary

```php
interface VoiceNoteTranscriber
{
    public function transcribe(TranscriptionRequest $request): TranscriptionResult;
}
```

- `OpenAiWhisperTranscriber` — the production driver (**D6**).
- `FakeVoiceNoteTranscriber` — deterministic, used in every test and available
  locally; the test environment forces it so no test reaches the network.
- `TranscriptionRequest` / `TranscriptionResult` are Spatie Data DTOs.
  `TranscriptionResult` carries text, confidence, `confidence_source`,
  `detected_language`, provider identity, and any provider error.
- Bound in a service provider from config, so business logic depends only on the
  interface. **ArchTest must assert that no class outside the driver namespace
  references the OpenAI client**, mirroring the existing stock-write ban.

Configuration — env vars plus `config/services.php` (OpenAI credentials, the
established location) and `config/employees.php` (driver switch, limits,
scoring default):

```text
OPENAI_API_KEY
OPENAI_TRANSCRIBE_MODEL=whisper-1
OPENAI_TRANSCRIBE_BASE_URL
OPENAI_TRANSCRIBE_TIMEOUT=120
EMPLOYEES_TRANSCRIBE_DRIVER=openai          # openai | fake  (tests force fake)
EMPLOYEES_TRANSCRIBE_MAX_BYTES=26214400     # 25 MiB provider request limit
EMPLOYEES_DEFAULT_REQUIRED_VISIT_MINUTES=30 # D5 fallback threshold
```

Document all of these in `Docs/CONFIGURATION.md` and `.env.example`.

Retry policy — bounded, and deliberately not uniform:

- `$tries = 3` with exponential backoff (e.g. `[60, 300]` seconds).
- Retry only transport failures, timeouts, HTTP 429 and 5xx.
- **Never retry** a 4xx caused by the payload itself (unsupported format, file
  too large, empty audio) — those go straight to `Failed` with the provider
  message, because retrying cannot change the outcome.
- `failed()` writes `TranscriptionStatus::Failed`, `VoiceNoteStatus::Failed`,
  and `error_message`, and touches nothing else.

### 12.3 Language coverage and the confidence fallback

**Language handling (FR-055).** Whisper auto-detects language, which is what
mixed Arabic/English recordings need. Therefore:

- pass the `language` parameter **only** when `employee_voice_notes.language` is
  explicitly set; leave it unset otherwise so detection can run;
- store the detected language in `voice_note_transcriptions.detected_language`;
- Arabic dialects transcribe into Arabic script with dialect vocabulary
  preserved; accuracy is lower than for Modern Standard Arabic, and the same is
  true for strongly accented English. Record this as a known limitation in
  `contracts/voice-note-ai.md`.
- *Optional, off by default:* the driver may pass the active `AiKeywordRule`
  keywords as the Whisper `prompt` to bias recognition of product names. This is
  a documented enhancement, not a requirement, and must be behind a config flag
  so it can be disabled without code changes.

**The confidence fallback (FR-056) — the important part.** The OpenAI audio
transcription API **does not return a calibrated confidence score**. With
`response_format=verbose_json` it returns per-segment `avg_logprob`,
`no_speech_prob` and `compression_ratio` — log-probabilities, not a confidence
percentage. The plan therefore defines an explicit, labelled behaviour rather
than inventing a number:

| Situation | `confidence` | `confidence_source` | UI |
|---|---|---|---|
| Provider returns a genuine confidence field | the value, 0.00–100.00 | `ProviderReported` | `87.50%` |
| `verbose_json` segments with `avg_logprob` available | duration-weighted mean of `exp(avg_logprob)` × 100, clamped to 0.00–100.00 | `DerivedFromLogProb` | `≈ 87.50%` + tooltip naming the derivation |
| No segments, no log-probabilities, or a non-verbose response format | `NULL` | `Unavailable` | "Not reported by provider" |

Rules that make this honest and testable:

- `0.00 <= confidence <= 100.00` whenever it is non-null; both boundaries tested.
- `confidence` is `NULL` **if and only if** `confidence_source = Unavailable`.
  A missing confidence is never stored as `0.00`, because `0.00` means the model
  had zero confidence — a materially different claim.
- A derived value is never labelled `ProviderReported`. The UI must surface the
  distinction, not bury it.
- Because derived values run lower for dialect and accented audio, confidence
  **must not** be used to auto-reject or auto-approve anything — consistent with
  Principle V, every AI output still needs a human decision.

---

## 13. Audit, reports, localization

**Audit (FR-084)** — reuse `AuditLogger`; add no second trail. Sensitive
actions to log: employee create/activate/deactivate/archive/restore; plan
create/update/status-transition/delete/restore and `plan.copied` (**D9**); task
status transitions including reopen; **every visit review-note create and
update, with `old_values`/`new_values` (D7)**; admin field-edits of a visit;
voice-note deletion; draft approve/reject; performance recalculation; salary
calculate/confirm/supersede; bonus approve/reject; role assignment. The existing
`AuditLogResource` already renders these.

The review-note audit entries carry a second duty: because **D7** stores only
the latest note text, `audit_logs.old_values` *is* the note's revision history.
A test must assert an update writes both the previous and the new text.

**Reports (FR-071, FR-072)** — `EmployeeReportResource` + `EmployeeReportType`
+ `EmployeeReportService`, following `InventoryReportResource` /
`InventoryReportService` / `InventoryReportFilters`. Exports go through a
queued job like `GenerateInventoryExport` (Principle IV).

**Localization** — new strings in `lang/en/admin.php` under an `employees` key,
mirroring the `crm` block's shape (`fields`, `actions`, `status`, `errors`,
`placeholders`). The `status` block needs every case of the eleven status enums
(§8.12); `errors` needs a message per rejected transition (§8.13), the
plan-copy conflict (**D9**), the null payable base (**D3**), and the
confidence-unavailable label (**D6**). Spec 013 established English-only for new
features; add `lang/ar/admin.php` entries only if the owner asks. An
`EmployeeEnglishLabelsTest` should mirror `CrmEnglishLabelsTest`.

---

## 14. Settled decisions

All ten decisions below are approved by the project owner and dated
**2026-08-04**. They are reflected throughout §1–§13; carry them verbatim into
`spec.md` and `contracts/`.

**There are no remaining functional clarifications.** No work package is blocked
by an open question. The only outstanding prerequisite is the ADR 0003
governance approval in WP0 (§5.1), which is a sign-off, not a design question.

| ID | Question | Decision | Consequences |
|---|---|---|---|
| **D1** | ERD's custom file storage vs Constitution IV | **Use Spatie Media Library; correct the ERD.** | `visit_attachments` is not created; `employee_voice_notes.audio_path` becomes a private single-file media collection. ERD edit in WP0. No constitution exception needed for storage. |
| **D2** | Which number drives salary (FR-060 vs FR-063) | **The weighted `total_score`.** | `employee_salary_calculations.performance_percent = total_score`. The completed÷total task ratio remains a displayed statistic, stored as `employee_performance_scores.task_completion_percent`. All four factor weights genuinely affect pay. |
| **D3** | Money source when `use_base_salary = false` | **A per-employee commission target.** | New `employee_profiles.commission_target_amount decimal(15,2)`, required in `PerformanceOnly` mode. Both salary modes collapse to one formula (§9.2). |
| **D4** | Meaning of "tasks cover 100% of the plan" (FR-022) | **The four plan factor weights must sum to exactly 100**, plus the plan must have at least one task. | No `plan_tasks.weight` column. `total_score` is always a clean 0–100 percentage, which is what makes D2 workable. |
| **D5** | Source of the schedule and work-time scores (FR-060) | **Existing task and visit data only. No attendance, shift, check-in, or working-hours module.** `schedule_adherence` = completed tasks finished on or before `due_at` ÷ total completed tasks. `work_time_adherence` = completed visits meeting the required duration ÷ total completed visits, duration = `checked_out_at − checked_in_at`. | New `plan_tasks.completed_at`; `plan_tasks.starts_at`/`due_at` become NOT NULL; new `sales_plans.required_visit_minutes` with a `config/employees.php` fallback. Zero-denominator rule applies to both factors. Effective threshold snapshotted into `calculation_breakdown`. §9.1, FR-026, FR-030, FR-035, FR-066, FR-067. |
| **D6** | AI transcription provider and confidence format | **OpenAI Whisper behind the `VoiceNoteTranscriber` abstraction; confidence stored as 0.00–100.00 in `decimal(5,2)`.** Must support English, Arabic, mixed Arabic/English, Arabic dialects, and varied English accents. Whisper returns no calibrated confidence, so the fallback is explicit and labelled — never fabricated. | OpenAI Whisper driver + fake driver; env vars in `config/services.php` and `config/employees.php`; queued job with bounded, failure-type-aware retries; failure isolation. New `voice_note_transcriptions.confidence_source` and `detected_language`. `confidence` is NULL iff source is `Unavailable`; never rendered as `0.00`. ArchTest bans OpenAI references outside the driver. §12, FR-051, FR-055, FR-056. |
| **D7** | Visit review notes (FR-044) | **One review note per visit, stored directly on `customer_visits`** as `review_note`, `reviewed_by`, `reviewed_at`. **No `visit_review_notes` table.** | Only `employees.visit.review` holders may create or update it, and the action stays available while a field-recorded visit is otherwise locked. Every create/update is audited with `old_values`/`new_values`, so the audit log is the note's revision history. §8.5, §9, §11.2, §13, FR-045. |
| **D8** | Status vocabularies | **The proposed enum case sets are approved**, with documented allowed and rejected transitions for plans, tasks, visits, voice notes, transcriptions, opportunity drafts, bonus suggestions and salary calculations. | §8.12 lists the approved cases (plus `TranscriptionConfidenceSource`, required by D6). §8.13 defines every transition. Enforcement lives on the enums and in domain services — never only in a hidden Filament button. Valid and invalid transitions are both tested. FR-008. |
| **D9** | Assigning the same plan to another employee (FR-020) | **Copy the plan.** Create a new independent `sales_plans` row for the target employee. **No shared many-to-many plan model.** | Copy/no-copy lists and month-clamping rules in §9.3. The copy starts `Draft` and owns its own status, tasks, performance score and salary calculations. Rejected with a clear message when the target already has an active plan for the target month. FR-020, FR-024. |
| **D10** | ADR 0003 scope | **The Employees Filament dashboard only.** | Authorised and not-authorised lists in §5.1. `/api/employee`, the employee mobile app, employee-app visit capture, employee-app attendance capture and mobile auth flows stay out of scope (§4, §19). A future employee API needs its own spec and a separate ADR or an explicit ADR 0003 amendment. |

---

## 15. Work packages

### WP0 — Governance and documentation gate *(blocking)*

1. Author `Docs/adr/0003-filament-employees-dashboard.md` with the **D10**
   authorised / not-authorised lists verbatim; obtain project-owner approval.
2. Amend `.specify/memory/constitution.md` to 1.4.0, regenerate the Sync Impact
   Report, and record that the amendment covers the dashboard only.
3. Encode all ten decisions (§14) into `spec.md`, including the FR additions
   FR-008, FR-026, FR-030 (dates required), FR-035, FR-045, FR-055, FR-056,
   FR-066, FR-067.
4. Write the §8 deltas into `Docs/database/ERD.md` — drop `visit_attachments`,
   add `completed_at`, `required_visit_minutes`, `confidence_source`,
   `detected_language`, the review-note columns, `payable_base`,
   `task_completion_percent`, `calculation_breakdown`, and the NOT NULL change
   on task dates — and the flows into `Docs/database/DFD.md`.
5. Sync `Docs/PRD.md`, `Docs/SDD.md`, `Docs/api/API_CONTRACT.md` (recording that
   no employee API is added), `Docs/architecture/SYSTEM_ARCHITECTURE.md`,
   `Docs/IMPLEMENTATION_PLAN.md`, `Docs/TESTING_STRATEGY.md`, and
   `Docs/CONFIGURATION.md` (the §12.2 env vars).
6. Produce `research.md`, `data-model.md`, `contracts/*` — including
   `performance-scoring.md` (§9.1–§9.2), `plan-lifecycle.md` (§8.13 + §9.3) and
   `voice-note-ai.md` (§12) — plus `quickstart.md`,
   `checklists/requirements.md`, `tasks.md`.
7. Run `/speckit-analyze`; clear all critical/high findings.

**Exit**: ADR 0003 approved and scoped to the dashboard, constitution amended,
ERD final, every decision encoded, zero open clarifications.

### WP1 — Schema, enums, permissions, transitions

Migration tests first, then 13 migrations, the 13 enums **with their
`allowedTransitions()`/`canTransitionTo()` guards and unit tests** (§8.13),
`InvalidStatusTransition`, `EmployeePermission`, `EmployeePermissionSeeder`,
`config/employees.php`, the `config/services.php` OpenAI block, and the §10.2
fixed-role-source fix with its regression tests.

**Exit**: fresh migrate + seed green on MySQL and SQLite; the unique-active-plan
index rejects duplicates at the DB level; task dates are NOT NULL; every
approved and rejected transition is covered by an enum unit test; an admin
holding only `Payroll Officer` or `Employee Manager` cannot manage CRM
customers.

### WP2 — Models, factories, policies

13 models with `#[Fillable]`, `casts()`, `TracksBlameable`, `SoftDeletes`,
relations, media collections; factories for all of them (with useful states,
including a `Completed`-with-`completed_at` task state and a completed visit
with and without check-out); 10 policies + `ChecksEmployeePermissions`.

**Exit**: model/relation/policy unit tests green; append-only guards on
`task_status_logs` and `visit_gps_logs` proven; the
`confidence`/`confidence_source` invariant enforced at the model layer.

### WP3 — Employee profile domain

`EmployeeOnboardingService`, `EmployeeAccessService`, unique-code generation,
archive/restore, and the `commission_target_amount` requirement when
`use_base_salary = false`. FR-010 – FR-014.

### WP4 — Plans and tasks domain

`SalesPlanService`, `SalesPlanDuplicationService` (**D9**, §9.3), `PlanTaskService`
with `completed_at` maintenance (**D5**), `TaskStatusLog` writing, and the
§8.13 transition guards at the service boundary. FR-020 – FR-035. This is the
invariant-heavy package: weight rule, one-active-plan rule, date-window rule,
delete-guard rule, copy/no-copy rule, month-clamping rule.

**Exit**: copying a plan onto an employee who already has an active plan for
that month is rejected before any write; a copied plan carries no execution
state; `completed_at` always agrees with `task_status_logs`.

### WP5 — Visits and location

Visit read model, GPS ordering, media attachments, `VisitReviewService`
(**D7**), field-record immutability with the review action still reachable, and
the derived duration used by scoring. FR-040 – FR-045.

**Exit**: a `field`-recorded visit rejects edits from a non-`field-edit` user at
the service layer while accepting a review note from a `visit.review` holder;
every note write is audited with old and new values.

### WP6 — Voice notes and AI

`VoiceNoteIntakeService`, the `VoiceNoteTranscriber` interface,
`OpenAiWhisperTranscriber`, `FakeVoiceNoteTranscriber`, the DTOs,
`TranscribeVoiceNoteJob` with the §12.2 retry policy, the §12.3
confidence-source mapping, `KeywordDetectionService`, `OpportunityReviewService`,
`AiKeywordRule` management. FR-050 – FR-056.

**Exit**: a throwing transcriber changes nothing outside the transcription row;
a 4xx payload error is not retried; `confidence` is null exactly when
`confidence_source = Unavailable`; ArchTest confirms no OpenAI reference outside
the driver; no test reaches the network.

### WP7 — Performance, salary, bonuses

`PerformanceScoringService` (pure, unit-tested against a table of cases
covering both **D5** factors), `SalaryCalculationService`,
`SalaryRecalculationService` with the confirm-before-apply flow and admin
notification, `BonusApprovalService`. FR-060 – FR-067.

**Exit**: the four zero-denominator cases behave per §9.1; the worked example
(8 of 10 tasks on time → 80%) reproduces exactly; the effective
`required_visit_minutes` is snapshotted in `calculation_breakdown`; completed
visits missing timestamps land in the denominator only.

### WP8 — Dashboard, reports, audit, localization

The six pinned resources + the four additional surfaces, registry sections,
`EmployeeReportResource`/`EmployeeReportType`/`EmployeeReportService`, queued
export, audit wiring, the §11.2 behaviours (confidence labelling, review-note
action on locked visits, transition errors surfaced as messages), English
strings, `PageUsageGuide` verification. FR-070 – FR-072, FR-080 – FR-085.

### WP9 — Verification and delivery gate

Full `composer test`; PHPStan baseline unchanged or smaller; 100% type and code
coverage held; `ArchTest` extended so no `App\Filament\Resources\Employees*`
namespace writes salary/performance rows directly (mirroring the existing
stock-write ban) and no class outside the transcription driver references the
OpenAI client; manual smoke of every screen in the running dashboard.

### 15.1 Dependency order

```text
WP0 ─> WP1 ─> WP2 ─┬─> WP3 ─┐
                   ├─> WP4 ─┤
                   ├─> WP5 ─┼─> WP7 ─> WP8 ─> WP9
                   └─> WP6 ─┘
```

WP3, WP4, WP5 and WP6 are independent once WP2 lands. WP7 needs WP4 (tasks and
`completed_at`) and WP5 (visit durations) because **D5** draws both adherence
factors from those two packages' data. WP8 needs everything.

---

## 16. Tasks skeleton

Feed this to `/speckit-tasks`. Phases map 1:1 to work packages; `[P]` marks
tasks touching independent files; `[US#]` maps to the spec's user stories.
Follow spec 013's convention: **write the failing test first**, and finish each
phase with an explicit "run and pass" task.

| Phase | WP | Approx. tasks | Checkpoint |
|---|---|---|---|
| 1 | WP0 | 9–11 | ADR 0003 approved and dashboard-scoped; ERD final; all ten decisions encoded |
| 2 | WP1 | 24–28 | Fresh schema + seeded roles; DB-level invariants proven; every transition guard unit-tested |
| 3 | WP2 | 26–30 | Models, factories, policies covered; confidence invariant enforced |
| 4 | WP3 | 8–10 | Employee lifecycle works standalone; commission target required when it must be |
| 5 | WP4 | 22–26 | Plan/task invariants + D9 copy semantics + `completed_at` integrity |
| 6 | WP5 | 14–16 | Field-recorded visit immutable yet reviewable; review-note audit history proven |
| 7 | WP6 | 18–22 | Whisper driver behind the interface; AI failure fully contained; confidence never fabricated |
| 8 | WP7 | 18–22 | Both D5 factors correct including all zero-denominator cases; salary reproducible from the row |
| 9 | WP8 | 24–28 | Ten screens live, permission-gated, English, audited; transition errors surfaced |
| 10 | WP9 | 6–7 | `composer test` green at 100/100; ArchTest bans satisfied |

---

## 17. Testing strategy

Pest 4. Feature tests in `tests/Feature/`, Filament-specific ones in
`tests/Feature/Filament/`, pure logic in `tests/Unit/`.

| Area | Test focus |
|---|---|
| Migrations | fresh schema shape; unique-active-plan index; no `visit_attachments` table; task dates NOT NULL; `completed_at` and `required_visit_minutes` present |
| Enums | case values; catalogue helpers; fixed-role mapping; **every allowed transition accepted and every rejected transition refused, per §8.13, including self-transitions** |
| Permissions | every ability maps to a permission; `Reviewer` can only read and cannot write a review note, confirm salary, or approve a bonus; **admin with only `Payroll Officer` or `Employee Manager` gains no CRM/Inventory bypass** |
| Policies | page authorization + direct action authorization + bulk-action authorization + service-level transition rejection when the UI is bypassed (FR-005–FR-008) |
| Plan invariants | factor weights sum to exactly 100 and ≥1 task (D4); one active plan per employee per month (service *and* DB); task dates required and inside the plan month; delete blocked after a completed task |
| Plan copy (D9) | every copied field present; no status, history, visit, score, salary, bonus or audit row copied; copied tasks start `Pending`; new plan starts `Draft`; rejected when the target has an active plan that month; month-length clamping (31 Jan → 28/29 Feb); whole copy rolls back as one transaction |
| Task history | one append-only log row per transition, with actor, time, note; `completed_at` set on completion, cleared on reopen, and always consistent with the latest `Completed` log entry |
| Visits | duration computation; GPS chronological order; field-record immutability at the service layer; **review note writable on a locked visit by a `visit.review` holder and refused for everyone else** |
| Review-note audit (D7) | create and update both audited; an update records the previous *and* new text, so the audit log is a usable revision history |
| AI isolation | throwing transcriber leaves visit/score/salary untouched; `error_message` persisted; retries bounded; **a 4xx payload error is not retried**; oversized audio rejected before dispatch; no test performs network I/O |
| Confidence (D6) | boundary values `0.00` and `100.00` accepted, out-of-range refused; `confidence` null **iff** `confidence_source = Unavailable`; a derived value is never labelled `ProviderReported`; the UI renders "Not reported by provider" and never `0.00%` for a null |
| Language (D6) | `language` omitted from the request when the voice note has none, passed when set; `detected_language` persisted from the response |
| Human gate | no draft or bonus reaches `Approved` without a recorded decision; `Approved`/`Rejected` are terminal |
| Scoring | table-driven unit tests: zero tasks, all complete, partial; **the D5 worked example — 8 of 10 tasks on time → 80%**; `completed_at == due_at` counts as on time; a completed visit missing check-out counts in the denominator only; effective `required_visit_minutes` resolved from the plan then from config, and snapshotted; a zero denominator on each of the four factors independently (scores 0, never divides by zero, never redistributes weight); rounding at `decimal(5,2)` boundaries; `total_score` equals `performance_percent` (D2) |
| Salary | both modes resolve `payable_base` from the right column (D3); null payable base is a validation failure, not a silent 0; only `Approved` bonuses are summed; recalculation on plan change; old row superseded not deleted; confirmation required; a stored row stays reproducible after the employee profile changes |
| Reports | each `EmployeeReportType`'s aggregate; filter combinations; queued export |
| Audit | one `audit_logs` row per sensitive action, including `plan.copied`; rollback discards it |
| Localization | no untranslated key; every status case and every transition-rejection message present; mirror `CrmEnglishLabelsTest` |
| Architecture | extend `ArchTest` for the employee namespaces and the OpenAI-client ban outside the driver |

Coverage: both thresholds stay at 100%. Every new file needs tests in the same
work package — deferring coverage to WP9 will not pass the gate.

---

## 18. Migration and rollback

All 13 migrations are additive; no existing table is modified except the
`app/Policies/Concerns/*` code fix in §10.2, which touches no schema. Because
every table is new and empty, rollback is a plain `down()` per migration in
reverse dependency order. No data backfill is required. `users` is untouched.

The NOT NULL constraint on `plan_tasks.starts_at`/`due_at` and the presence of
`completed_at` are defined in the tables' original creation migrations, not as
later alterations, since no data exists yet — matching the approach spec 013
took for the pricing-tier schema.

`employee_profiles` for existing employee-channel users, if any exist, must be
created by an explicit seeder or admin action, not by an implicit migration
backfill.

---

## 19. Explicit non-deliverables

Per **D10**, ADR 0003 authorises the Employees Filament dashboard only. The
following are explicitly not delivered and not authorised by it:

- `/api/employee` endpoints and any other employee-facing API functionality.
- The employee mobile application.
- Employee-app visit capture.
- Employee-app attendance capture.
- Mobile authentication flows.

Also not delivered:

- Any attendance, shift, check-in, or employee working-hours module (**D5**) —
  schedule and work-time adherence are computed from existing task and visit
  data.
- A `visit_review_notes` table or any threaded review-note model (**D7**).
- A shared or many-to-many plan model (**D9**) — assignment copies the plan.
- Payroll disbursement, accounting postings, or journal entries for salaries.
- Quotation/delivery-note creation during a visit.
- Tickets, maintenance, CRM leads, or marketing campaigns.
- Any autonomous AI action, and any use of confidence to auto-approve or
  auto-reject AI output.
- A second audit trail, permission store, media store, or report framework.
- Arabic UI strings, unless the owner requests them.
- Live map rendering of GPS trails (chronological list only, unless requested).

A future employee API requires its own specification and, where the constitution
requires it, a separate ADR or an explicit amendment to ADR 0003.

---

## 20. Completion definition

1. ADR 0003 is approved, scoped to the Employees Filament dashboard only per
   **D10**, and the constitution is amended to 1.4.0 with the authorised and
   not-authorised lists recorded.
2. Every FR in §6 maps to at least one passing test.
3. All ten decisions D1–D10 (§14) are reflected in the code, and no functional
   clarification remains open.
4. The ERD matches the shipped schema exactly, including the `visit_attachments`
   removal and every §8 delta.
5. Ten dashboard surfaces are reachable, permission-gated, English, and audited.
6. Every documented state transition (§8.13) is enforced in a domain service and
   covered by a passing and a failing test.
7. Schedule and work-time adherence are computed solely from existing task and
   visit data; no attendance, shift, or working-hours table exists.
8. Transcription runs through `VoiceNoteTranscriber`; no class outside the
   driver references the OpenAI client; no stored confidence is presented with a
   provenance it does not have.
9. No `/api/employee` route, employee-app surface, or mobile auth flow is added.
10. `composer test` exits successfully with 100% type coverage and 100% code
    coverage, and `phpstan-baseline.neon` has not grown.
11. No unrelated refactor is included beyond the §10.2 fixed-role fix, which is
    documented as required by the new roles.
