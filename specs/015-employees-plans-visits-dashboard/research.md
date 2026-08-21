# Phase 0 Research: Employees, Monthly Plans, Visits, Performance & Salary Dashboard

**Feature**: `specs/015-employees-plans-visits-dashboard` | **Date**: 2026-08-05

All Technical Context unknowns are resolved below. No `NEEDS CLARIFICATION` remains — the ten
project-owner decisions (D1–D10) already settle every open business question (`plan.md` §14); this
document captures the implementation-level research behind them and behind the areas the SRS left
to engineering judgment.

---

## R-001: Enforcing "one active plan per employee per month" on both MySQL and SQLite

**Decision**: Add a nullable stored column `sales_plans.active_month`, written by the service layer
inside the same transaction as the status change: it holds `month` when `status = Active` and
`NULL` for every other status. A `unique(['employee_id', 'active_month'])` index then rejects a
second active plan for the same employee/month at the database level.

**Rationale**: FR-023 needs a hard guarantee, not just a service-layer check, because Principle III's
transactional discipline is only as strong as its constraints. MySQL has no native filtered/partial
unique index, and the test suite runs on SQLite (`phpunit.xml`), so the index must work identically
on both engines without vendor-specific syntax. Both MySQL and SQLite treat multiple `NULL`s in a
unique index as distinct values, so every `Draft`, `Paused`, `Completed`, or `Archived` plan can
coexist freely while at most one `Active` plan per employee/month can ever exist — enforced even if
a caller bypasses `SalesPlanService` entirely.

**Alternatives considered**:

- *Service-layer check only (`where employee_id = ? and month = ? and status = 'Active'` before
  insert).* Rejected: a race between two concurrent requests can still insert two active rows: the
  check-then-insert is not atomic without a matching DB constraint.
- *Application-level advisory lock (Redis/DB lock) around plan activation.* Rejected: adds an
  infrastructure dependency for a problem a unique index already solves for free, and still needs
  the constraint as a backstop if the lock is skipped.
- *A separate `active_plan_markers` table with a unique `employee_id`.* Rejected: a second table to
  keep in sync is exactly the kind of parallel bookkeeping Principle II discourages when a single
  denormalized column on the row itself is sufficient and simpler to reason about.

---

## R-002: Deriving a confidence figure when Whisper reports none

**Decision**: When the OpenAI transcription response includes `verbose_json` segment data, derive a
confidence estimate as the duration-weighted mean of `exp(avg_logprob)` across segments, expressed
as a percentage and clamped to `0.00–100.00`, stored with `confidence_source = DerivedFromLogProb`.
When no segment data is available at all, store `NULL` with `confidence_source = Unavailable`. A
provider-reported confidence field, if one is ever returned, is stored verbatim with
`confidence_source = ProviderReported` and takes precedence over derivation.

**Rationale**: The OpenAI audio transcription API does not return a calibrated confidence score
(D6). `avg_logprob` is a log-probability, not a percentage, and duration-weighting it across segments
avoids letting one short, well-recognized segment dominate the estimate for a long, poorly
recognized recording. Because this is a derived estimate and not what the provider actually reports,
FR-056 requires the distinction to travel with the value so the UI never claims more certainty than
actually exists.

**Alternatives considered**:

- *Use `no_speech_prob` instead of `avg_logprob`.* Rejected as the sole signal: it measures
  "is this segment speech at all," not "how confident is the transcription of this segment,"
  answering a different question than the one the UI needs to show.
- *Store the raw log-probability instead of a 0–100 percentage.* Rejected: the schema
  (`confidence decimal(5,2)`) and every other confidence consumer in the UI expect a
  percentage; forcing every reader to know the log-probability transform duplicates logic instead
  of centralizing it once in the transcriber.
- *Default missing confidence to `0.00`.* Rejected outright by D6/FR-056: `0.00` asserts "zero
  confidence," a different and false claim from "no confidence data available."

---

## R-003: Retry policy for the transcription job

**Decision**: `TranscribeVoiceNoteJob` retries up to 3 times with backoff `[60, 300]` seconds, but
only for transport failures, timeouts, HTTP 429, and 5xx responses. A 4xx caused by the payload
itself (unsupported audio format, file exceeding the provider limit, an empty recording) fails
immediately with the provider's message and is never retried.

**Rationale**: Principle V requires AI failure to never block the surrounding workflow, but a bounded
retry still needs to distinguish failures a retry can fix from failures it cannot. A malformed or
oversized file will fail identically on every attempt, so retrying it only delays the operator's
visibility into the real problem and wastes a queue slot. Transient failures (network blip, rate
limit, provider outage) are exactly what bounded retries with backoff are for.

**Alternatives considered**:

- *Retry every failure the same number of times.* Rejected: retrying a guaranteed-to-fail payload
  error delays `error_message` reaching the reviewer for no benefit.
- *Unlimited retries.* Rejected: violates the "failure isolation" requirement in a different way —
  an endlessly retrying job for a permanently bad recording consumes queue capacity indefinitely.

---

## R-004: Rebasing task dates when a plan is copied into a shorter month

**Decision**: Each task's date offset is computed from the *source* month's first day, then applied
to the *target* month's first day. If the resulting date would fall beyond the target month's last
day, it clamps to the target month's last day instead of rolling into the next month.

**Rationale**: FR-024/D9 requires copying a plan's tasks into a new month while keeping every date
inside that month (FR-032). A naive day-of-month copy (e.g., "the 31st") is undefined for months with
fewer days; silently rolling into the next month would put a task outside its own plan's window,
which FR-032 forbids outright. Clamping is the only option that satisfies both requirements
simultaneously and matches how the SRS's worked example describes the behavior explicitly (31 January
→ 28/29 February, never March).

**Alternatives considered**:

- *Roll over into the next month.* Rejected: violates FR-032 (task dates must fall inside the plan's
  own month).
- *Reject the copy when any task would need clamping.* Rejected: this would make a common, harmless
  case (copying a 31-day month's plan into a 28/29-day month) fail for a reason unrelated to any
  real business rule, when clamping already produces a sensible, in-window date.

---

## R-005: Visit attachments and voice-note audio storage

**Decision**: Drop the ERD's standalone `visit_attachments` table; register a private `visit-attachments`
Spatie Media Library collection on `CustomerVisit` instead. Replace `employee_voice_notes.audio_path`
with a private single-file `voice-note-audio` media collection on the same model pattern already used
by `CustomerProfile`.

**Rationale**: Constitution Principle IV requires all uploaded files — explicitly including voice-note
audio — to use Spatie Media Library, and prohibits a custom per-feature file table unless a concrete
requirement proves one is needed (D1). Nothing in the SRS requires attachment metadata beyond what
Media Library's `custom_properties` already covers, so no such requirement exists here. Reusing the
existing pattern also means audio playback goes through the same private-disk, signed-URL mechanism
already proven for other sensitive documents, rather than a second, bespoke serving path.

**Alternatives considered**:

- *Keep `visit_attachments` as specified in the ERD, request a constitution exception.* Rejected:
  no functional requirement needs a bespoke table; the constitution's own escape hatch ("unless a
  concrete future requirement proves they are needed") does not apply here, so correcting the ERD is
  simpler than seeking an exception for a table nothing actually requires.
- *Store `audio_path` as a plain column pointing at a public disk path.* Rejected: contradicts
  Principle IV directly and would serve sensitive recordings from a public path rather than a
  temporary signed URL (FR-083).

---

## R-006: A pre-existing cross-module permission leak that the new roles would widen

**Decision**: Introduce one shared source of truth for the fixed dashboard role names (all modules'
`System Admin`, `Employee Manager`, `Payroll Officer`, `CRM Manager`, `Pricing Manager`, `Reviewer`,
etc.) that every module's `Checks*Permissions` trait consults, replacing each module's private
`fixedRoleNames()` list.

**Rationale**: `ChecksCrmPermissions::authorizeCrmAbility()` (and the equivalent Inventory trait)
grants any `isAdmin()` user full module access unless that user holds one of *that module's own*
fixed roles. `CrmPermission::fixedRoleNames()` does not know about the new `Employee Manager` or
`Payroll Officer` roles, so an admin whose only role is one of those two would silently pass the CRM
bypass check and gain full CRM access — a real, provable security gap this feature would otherwise
introduce by adding new admin-flagged roles. Centralizing the fixed-role list is the fix that scales:
every future module addition narrows the bypass everywhere automatically, instead of requiring every
existing trait to be edited again.

**Alternatives considered**:

- *Add the two new role names to `CrmPermission::fixedRoleNames()` and `InventoryPermission`'s
  equivalent, and leave the pattern as-is.* Rejected: this pattern breaks again the next time any
  module adds a role, silently, until someone notices the leak — it treats a design flaw's symptom
  rather than its cause.
- *Leave the leak unfixed and document it as a known limitation.* Rejected: this is a real
  unauthorized-access defect this feature would introduce, not a pre-existing condition it can
  reasonably ignore; CLAUDE.md's "small, reviewable changes" principle is satisfied because the fix
  is narrowly scoped to the shared trait/enum, not a broader refactor.

---

## R-007: Why schedule and work-time adherence do not need an attendance module

**Decision**: Schedule adherence is computed from `plan_tasks.completed_at` vs. `due_at`; work-time
adherence is computed from `customer_visits.checked_in_at`/`checked_out_at` vs. a required-duration
threshold. No attendance, shift, check-in, or working-hours table is introduced.

**Rationale**: D5 settles this as a business decision, but the technical question — "is there enough
existing data to compute these two factors honestly?" — was independently verified: every input
(task due dates, task completion timestamps, visit check-in/check-out timestamps) already exists on
tables this feature creates for other reasons (FR-030, FR-035, FR-041). Introducing an attendance
concept would duplicate data this feature already owns and would expand ADR 0003's scope beyond the
dashboard-only exception it was granted.

**Alternatives considered**:

- *Add a lightweight `employee_attendance` table to make "work time" mean clock-in/clock-out for the
  day.* Rejected: not required by any FR, and explicitly out of scope per D5 and ADR 0003 §"Not
  authorised."

---

## R-008: Report export mechanism

**Decision**: `EmployeeReportResource` exports through a queued job, following the existing
`RequestsInventoryExports` concern and `InventoryExportType` pattern already used by
`InventoryReportResource`.

**Rationale**: The SRS does not specify an export file format, and Constitution Principle IV already
requires exports to run through queued jobs rather than synchronously in the request cycle. Reusing
the exact mechanism already proven for Inventory reports avoids inventing a second export pipeline
for a requirement (FR-071, FR-072) that is functionally identical: a filtered, paginated report the
user can also download.

**Alternatives considered**:

- *Synchronous CSV download.* Rejected: violates Principle IV's "long-running operations MUST run
  through queued jobs" rule; report generation over a large employee/plan/visit dataset is exactly
  the kind of operation that rule exists for.
