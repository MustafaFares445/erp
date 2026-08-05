# Phase 1 Data Model: Employees, Monthly Plans, Visits, Performance & Salary Dashboard

**Feature**: `specs/015-employees-plans-visits-dashboard` | **Date**: 2026-08-05

13 tables, all new and additive — no existing table is modified. This is `Docs/database/ERD.md`'s
14 employee-module tables minus `visit_attachments` (R-005/D1). Every delta from the ERD's original
column set is called out explicitly; anything not called out matches the ERD as written.

Conventions applied throughout, matching existing migrations: `foreignId()->constrained()` with an
explicit delete behavior; blameable `created_by`/`updated_by` nullable FKs + `TracksBlameable`;
`softDeletes()` where the ERD specifies `deleted_at`; money as `decimal(15,2)`; scores, weights, and
percentages as `decimal(5,2)`; GPS coordinates as `decimal(10,7)`; every FK indexed, plus `status`
and any date column used in filtering.

State-transition rules for every status enum below live in
[contracts/plan-lifecycle.md](./contracts/plan-lifecycle.md) rather than being repeated here.
Scoring and salary formulas live in
[contracts/performance-scoring.md](./contracts/performance-scoring.md).

---

## Domain overview

```text
User (user_type = employee)
 └─1:1─ EmployeeProfile ──1:N── SalesPlan (one active per employee per month)
                          │        ├─1:N── PlanTask ──1:N── TaskStatusLog
                          │        │           └─N:1── CustomerProfile (nullable)
                          │        ├─1:1── EmployeePerformanceScore
                          │        └─1:N── EmployeeSalaryCalculation (one current, rest superseded)
                          ├─1:N── CustomerVisit ──1:N── VisitGpsLog
                          │           ├─ media: visit-attachments        (Media Library)
                          │           ├─ review_note / reviewed_by / reviewed_at
                          │           └─1:N── EmployeeVoiceNote
                          │                      ├─ media: voice-note-audio (Media Library)
                          │                      └─1:1── VoiceNoteTranscription
                          │                                  └─1:N── SalesOpportunityDraft
                          └─1:N── BonusSuggestion ──N:1── SalesOpportunityDraft (nullable)

AiKeywordRule ──N:1── Product / ProductVariant (both nullable)
```

A plan belongs to exactly one employee. Assigning "the same plan" to a second employee creates a
second, fully independent `SalesPlan` row (D9) — there is no many-to-many or shared plan model. A
`CustomerVisit` reaches a plan only indirectly, through `plan_task_id → plan_tasks.sales_plan_id`;
`plan_task_id` is nullable, so an ad-hoc visit can exist outside any plan (see
[contracts/performance-scoring.md](./contracts/performance-scoring.md) for how that is handled in
scoring).

---

## 1. `employee_profiles`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `user_id` | fk → `users` | no | unique; `cascadeOnDelete`; `user_type` must be `Employee` |
| `employee_code` | string(50) | no | unique **including against soft-deleted rows** (`withTrashed()` on generation, mirroring `CustomerOnboardingService`) |
| `job_title` | string(150) | no | |
| `phone` | string(30) | yes | **delta** — FR-010 requires contact data; absent from ERD |
| `email` | string(150) | yes | **delta** — same reason |
| `is_active` | boolean | no | **delta**, default `true` — `users` has no `is_active`; mirrors `customer_profiles.is_active` (FR-012) |
| `use_base_salary` | boolean | no | default `true` |
| `base_salary` | decimal(15,2) | yes | required when `use_base_salary = true` (validated in service, not DB) |
| `commission_target_amount` | decimal(15,2) | yes | **delta (D3)** — required when `use_base_salary = false`; the payable base for `PerformanceOnly` mode |
| `salary_calculation_mode` | string(30) | no | cast to `SalaryCalculationMode` |
| `created_by` / `updated_by` | fk → `users` | yes | `TracksBlameable` |
| timestamps, `softDeletes` | | | archiving (FR-013) is a soft delete, never a hard delete |

**Relationships**: `belongsTo User`; `hasMany SalesPlan`; `hasMany CustomerVisit`; `hasMany BonusSuggestion`.

**Validation**: exactly one profile per employee-channel user; `base_salary` XOR `commission_target_amount`
required, driven by `use_base_salary` (never both null — a null payable base is a validation error,
not a silent zero, per [contracts/performance-scoring.md](./contracts/performance-scoring.md) §Salary).

---

## 2. `sales_plans` (the "Monthly Plan")

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `employee_id` | fk → `employee_profiles` | no | `cascadeOnDelete`; indexed with `month` |
| `name` | string(150) | no | |
| `month` | date | no | normalized to the first day of the month on write; indexed |
| `active_month` | date | yes | **delta** — mirrors `month` only while `status = Active`, else `NULL`; see R-001 |
| `task_weight` / `visit_weight` / `schedule_weight` / `work_time_weight` | decimal(5,2) | no | must sum to exactly 100 (D4) |
| `required_visit_minutes` | unsigned smallint | yes | **delta (D5)** — falls back to `config('employees.default_required_visit_minutes')` when null |
| `status` | string(30) | no | cast to `SalesPlanStatus`; default `Draft` |
| `created_by` / `updated_by` | fk → `users` | yes | `TracksBlameable` |
| timestamps, `softDeletes` | | | |

**Indexes**: `unique(['employee_id', 'active_month'])` (R-001); `(employee_id, month)`.

**Relationships**: `belongsTo EmployeeProfile`; `hasMany PlanTask`; `hasOne EmployeePerformanceScore`;
`hasMany EmployeeSalaryCalculation`.

**Validation**: weights sum to exactly 100 and ≥1 task before activation (D4, FR-022); at most one
`Active` plan per employee per month (FR-023, enforced at both service and DB layers per R-001);
deletion blocked once any task on the plan has been completed (FR-025).

---

## 3. `plan_tasks`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `sales_plan_id` | fk → `sales_plans` | no | `cascadeOnDelete`; indexed with `status` |
| `customer_id` | fk → `customer_profiles` | yes | `nullOnDelete` (FR-031) |
| `title` | string(200) | no | |
| `description` | text | yes | |
| `starts_at` | date | **no** | **delta** — ERD has this nullable; FR-030 requires it |
| `due_at` | date | **no** | **delta** — load-bearing for schedule adherence (D5); ERD has this nullable |
| `completed_at` | timestamp | yes | **delta** — set on entering `Completed`, cleared on reopen (D5, FR-035) |
| `status` | string(30) | no | cast to `PlanTaskStatus`; default `Pending` |
| `created_by` / `updated_by` | fk → `users` | yes | `TracksBlameable` |
| timestamps, `softDeletes` | | | |

**No per-task `weight` column** — resolved by D4; the four weights live on the plan, not the task.

**Indexes**: `(sales_plan_id, status)`; `due_at`; `completed_at`.

**Relationships**: `belongsTo SalesPlan`; `belongsTo CustomerProfile` (nullable); `hasMany TaskStatusLog`;
`hasMany CustomerVisit` (via `plan_task_id`).

**Validation**: `starts_at`/`due_at` must fall within the parent plan's month window (FR-032, never
before or after it).

---

## 4. `task_status_logs`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `plan_task_id` | fk → `plan_tasks` | no | `cascadeOnDelete` |
| `from_status` | string(30) | yes | null for the task's initial log entry |
| `to_status` | string(30) | no | cast to `PlanTaskStatus` |
| `note` | text | yes | |
| `actor_id` | fk → `users` | yes | `nullOnDelete` |
| `created_at` | timestamp | no | |

**Append-only**: no `updated_at`, no soft delete, no update path — enforced by a model-level guard
and a test (FR-033). `plan_tasks.completed_at` must always agree with the latest `Completed` entry
here; a test asserts this invariant directly rather than trusting the two to stay in sync by
convention.

---

## 5. `customer_visits`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `employee_id` | fk → `employee_profiles` | no | `cascadeOnDelete`; indexed with `status` |
| `plan_task_id` | fk → `plan_tasks` | yes | `nullOnDelete` — null means an ad-hoc, plan-unattributed visit; indexed with `status` |
| `customer_id` | fk → `customer_profiles` | yes | `nullOnDelete` (FR-040) |
| `recorded_channel` | string(20) | no | **delta** — `VisitRecordChannel`; default `Dashboard`; `Field` is only ever written by the (out-of-scope) employee app |
| `planned_at` | timestamp | yes | |
| `checked_in_at` | timestamp | yes | |
| `checked_out_at` | timestamp | yes | |
| `outcome` | text | yes | |
| `review_note` | text | yes | **delta (D7)** — exactly one current note per visit |
| `reviewed_by` | fk → `users` | yes | **delta (D7)** — `nullOnDelete` |
| `reviewed_at` | timestamp | yes | **delta (D7)** | |
| `status` | string(20) | no | cast to `VisitStatus`; default `Planned` |
| `created_by` / `updated_by` | fk → `users` | yes | `TracksBlameable` |
| timestamps, `softDeletes` | | | |

`duration_minutes` is **derived**, never stored: `checked_out_at − checked_in_at` (FR-041).

**Media**: private `visit-attachments` collection (images/files) replacing the ERD's
`visit_attachments` table (D1/R-005).

**Indexes**: `(plan_task_id, status)`; `(employee_id, status)`.

**Relationships**: `belongsTo EmployeeProfile`; `belongsTo PlanTask` (nullable); `belongsTo CustomerProfile`
(nullable); `hasMany VisitGpsLog`; `hasMany EmployeeVoiceNote`.

**Validation**: a visit with `recorded_channel = Field` is immutable except to a
`employees.visit.field-edit` holder; the `review_note`/`reviewed_by`/`reviewed_at` triple remains
writable regardless, by a `employees.visit.review` holder (FR-044, FR-045). Every create/update of
the review note is written to `audit_logs` with `old_values`/`new_values` (D7), which is the note's
full revision history since only the latest text is stored on the row.

---

## 6. `visit_gps_logs`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `customer_visit_id` | fk → `customer_visits` | no | `cascadeOnDelete` |
| `latitude` | decimal(10,7) | no | |
| `longitude` | decimal(10,7) | no | |
| `recorded_at` | timestamp | no | |

**Append-only**, ordered by `recorded_at`; index `(customer_visit_id, recorded_at)` (FR-042).

---

## 7. `employee_voice_notes`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `customer_visit_id` | fk → `customer_visits` | no | `cascadeOnDelete` |
| `employee_id` | fk → `employee_profiles` | no | `cascadeOnDelete` |
| `language` | string(20) | yes | operator-set hint; may differ from what is actually detected |
| `duration_seconds` | unsigned int | yes | |
| `status` | string(20) | no | cast to `VoiceNoteStatus`; default `Pending` |
| `created_by` / `updated_by` | fk → `users` | yes | `TracksBlameable` |
| timestamps, `softDeletes` | | | |

**Media**: private single-file `voice-note-audio` collection replaces the ERD's `audio_path` column
(D1/R-005); served through a temporary signed URL (FR-083).

**Relationships**: `belongsTo CustomerVisit`; `belongsTo EmployeeProfile`; `hasOne VoiceNoteTranscription`.

---

## 8. `voice_note_transcriptions`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `employee_voice_note_id` | fk → `employee_voice_notes` | no | unique; `cascadeOnDelete` |
| `transcript` | text | yes | |
| `confidence` | decimal(5,2) | yes | `0.00 <= confidence <= 100.00`; **`NULL` iff `confidence_source = Unavailable`** (D6) |
| `confidence_source` | string(30) | no | **delta (D6)** — `TranscriptionConfidenceSource`: `ProviderReported` \| `DerivedFromLogProb` \| `Unavailable` |
| `detected_language` | string(20) | yes | **delta (D6)** — what the provider actually detected, which may differ from `employee_voice_notes.language` |
| `provider` | string(50) | yes | concrete driver identity, e.g. `openai.whisper-1` |
| `error_message` | text | yes | provider-side failure reason (FR-051) |
| `status` | string(20) | no | cast to `TranscriptionStatus`; default `Pending` |
| timestamps | | | |

**Relationships**: `belongsTo EmployeeVoiceNote`.

**Validation**: `confidence` is non-null exactly when `confidence_source` is `ProviderReported` or
`DerivedFromLogProb`; a derived value is never labeled `ProviderReported`. Enforced in the service
and tested at both confidence boundaries (`0.00`, `100.00`).

---

## 9. `ai_keyword_rules`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `keyword` | string(150) | no | indexed |
| `product_id` | fk → `products` | yes | `nullOnDelete` — a rule with neither product link is a valid text-only match |
| `product_variant_id` | fk → `product_variants` | yes | `nullOnDelete` |
| `is_active` | boolean | no | default `true`; indexed |
| `created_by` / `updated_by` | fk → `users` | yes | `TracksBlameable` |
| timestamps, `softDeletes` | | | |

**Relationships**: `belongsTo Product` (nullable); `belongsTo ProductVariant` (nullable).

---

## 10. `sales_opportunity_drafts`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `voice_note_transcription_id` | fk → `voice_note_transcriptions` | no | `cascadeOnDelete` |
| `ai_keyword_rule_id` | fk → `ai_keyword_rules` | yes | `nullOnDelete` |
| `summary` | text | no | |
| `status` | string(20) | no | cast to `OpportunityDraftStatus`; default `Draft` |
| `reviewed_by` | fk → `users` | yes | **delta** — makes FR-054 provable from the row itself |
| `reviewed_at` | timestamp | yes | **delta** | |
| `review_notes` | text | yes | **delta** | |
| timestamps | | | |

**Relationships**: `belongsTo VoiceNoteTranscription`; `belongsTo AiKeywordRule` (nullable); `hasMany BonusSuggestion`
(nullable back-reference).

**Validation**: `Approved`/`Rejected` are terminal; a changed decision requires a new draft, never a
rewrite of a decided one (FR-054).

---

## 11. `employee_performance_scores`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `sales_plan_id` | fk → `sales_plans` | no | unique with `employee_id`; `cascadeOnDelete` |
| `employee_id` | fk → `employee_profiles` | no | `cascadeOnDelete` |
| `task_score` / `visit_score` / `schedule_score` / `work_time_score` | decimal(5,2) | no | |
| `total_score` | decimal(5,2) | no | drives salary (D2) |
| `task_completion_percent` | decimal(5,2) | no | **delta** — FR-063 display-only statistic, distinct from `total_score` |
| `calculation_breakdown` | json | no | **delta** — per-factor numerator/denominator/ratio/weight/contribution, plus the effective `required_visit_minutes` and excluded-visit counts (FR-061) |
| `calculated_at` | timestamp | no | **delta** | |

**Indexes**: `unique(['sales_plan_id', 'employee_id'])`.

**Relationships**: `belongsTo SalesPlan`; `belongsTo EmployeeProfile`.

---

## 12. `employee_salary_calculations`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `sales_plan_id` | fk → `sales_plans` | no | `cascadeOnDelete` |
| `employee_id` | fk → `employee_profiles` | no | `cascadeOnDelete` |
| `payable_base` | decimal(15,2) | no | **delta** — resolved base at calculation time (D2/D3), not re-derived later |
| `performance_percent` | decimal(5,2) | no | = `total_score` at calculation time (D2) |
| `bonus_amount` | decimal(15,2) | no | sum of `Approved` bonus suggestions only |
| `final_salary` | decimal(15,2) | no | `payable_base × (performance_percent / 100) + bonus_amount`, rounded once |
| `status` | string(30) | no | cast to `SalaryCalculationStatus`; default `Draft` |
| `confirmed_by` | fk → `users` | yes | **delta** | |
| `confirmed_at` | timestamp | yes | **delta** | |
| `superseded_by_id` | fk → `employee_salary_calculations` | yes | **delta** — self-referencing; set when a recalculation replaces this row |
| `superseded_at` | timestamp | yes | **delta** | |
| timestamps | | | never physically deleted — corrections go through supersession |

**Relationships**: `belongsTo SalesPlan`; `belongsTo EmployeeProfile`; `belongsTo EmployeeSalaryCalculation`
(`superseded_by_id`, nullable self-reference).

**Validation**: `payable_base` is never null (a missing base is a validation failure — FR-062); a
`Confirmed` row transitions only to `Superseded`, and only via a fresh recalculation; no row is ever
deleted (FR-065).

---

## 13. `bonus_suggestions`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint pk | no | |
| `employee_id` | fk → `employee_profiles` | no | `cascadeOnDelete` |
| `sales_plan_id` | fk → `sales_plans` | no | `cascadeOnDelete` |
| `sales_opportunity_draft_id` | fk → `sales_opportunity_drafts` | yes | `nullOnDelete` |
| `amount` | decimal(15,2) | no | |
| `reason` | text | no | |
| `status` | string(20) | no | cast to `BonusSuggestionStatus`; default `Pending` |
| `approved_by` | fk → `users` | yes | **delta** | |
| `approved_at` | timestamp | yes | **delta** | |
| `decision_notes` | text | yes | **delta** | |
| timestamps | | | |

**Relationships**: `belongsTo EmployeeProfile`; `belongsTo SalesPlan`; `belongsTo SalesOpportunityDraft` (nullable).

**Validation**: `Approved`/`Rejected` are terminal (FR-064); only `Approved` rows contribute to
`final_salary`.

---

## Enum catalogue

13 new enums under `app/Enums/`, all backed string enums with `declare(strict_types=1)` and
TitleCase cases (D8):

| Enum | Cases |
|---|---|
| `EmployeePermission` | see [contracts/permissions.md](./contracts/permissions.md) |
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

Every status enum except `EmployeeReportType`, `VisitRecordChannel`, and `SalaryCalculationMode`
(which are classifications, not lifecycles) carries `allowedTransitions(): array` and
`canTransitionTo(self $to): bool` — see
[contracts/plan-lifecycle.md](./contracts/plan-lifecycle.md) for every allowed and rejected
transition.
