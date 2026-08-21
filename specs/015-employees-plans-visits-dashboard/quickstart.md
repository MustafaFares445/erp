# Quickstart: Validating the Employees Dashboard

**Feature**: `specs/015-employees-plans-visits-dashboard` | **Date**: 2026-08-05

How to prove this feature works end to end. Scenarios map to the spec's eight user stories and can
be run independently, in story order.

---

## Prerequisites

```bash
php artisan migrate
```

```bash
php artisan db:seed --class=EmployeePermissionSeeder
```

Force the fake transcription driver locally so no scenario reaches the network:

```bash
EMPLOYEES_TRANSCRIBE_DRIVER=fake
```

(the test environment forces this automatically; it only needs setting for a manual walkthrough).

Local Xdebug is configured for coverage (`php.ini`, `xdebug.mode = develop,debug,coverage`), so
`composer test:coverage` works without extra setup.

---

## Scenario 1 — Roles and permissions (US1, run first)

```bash
php artisan test --compact --filter=EmployeePermission
```

**Manual walkthrough**:

1. Sign in as `System Admin`; confirm every one of the ten dashboard surfaces (see
   [contracts/dashboard-ui.md](./contracts/dashboard-ui.md)) is reachable and every action available.
2. Sign in as `Employee Manager`; confirm employee/plan/task management and visit review work, and
   that Salary/Bonus actions are absent or 403.
3. Sign in as `Payroll Officer`; confirm performance review, salary calculation, and bonus approval
   work, and that employee/plan/task management is denied.
4. Sign in as `Reviewer`; confirm every list and report is visible and every write action (review
   note, salary confirm, bonus approve) is denied.
5. As any role, try a direct action call bypassing the visible button (e.g. via `artisan tinker`
   invoking the underlying Filament action class) and confirm the same permission boundary applies.

---

## Scenario 2 — Employee profiles (US2)

```bash
php artisan test --compact --filter=EmployeeProfile
```

**Manual walkthrough** — Employees → New:

1. Create an employee with a job title and contact data. A unique `employee_code` is assigned.
2. Archive the employee, then create a new employee and confirm the archived employee's code is
   never reused.
3. Disable "use base salary" without setting a commission/target amount; save is rejected.
4. Set the commission/target amount and save; the employee now uses `PerformanceOnly` mode.
5. Search by code and by name; filter by status and job title.

---

## Scenario 3 — Monthly plans (US3)

```bash
php artisan test --compact --filter=SalesPlan
```

**Manual walkthrough** — Monthly Plans → New:

1. Set weights that sum to 99; save is rejected with the sum shown inline.
2. Set weights that sum to 100 with zero tasks; save is still rejected (needs ≥1 task).
3. Add a task, save; the plan activates.
4. Try to activate a second plan for the same employee and month; rejected.
5. "Copy to month" this plan into a shorter month (e.g. copy a plan with a task due 31 January into
   February); confirm the copied task lands on 28 or 29 February, not March.
6. "Assign to another employee" who already has an active plan that target month; rejected with the
   conflicting plan named, before any row is written.
7. Delete a plan with no completed tasks (succeeds); complete a task on a plan, then try to delete it
   (rejected).

---

## Scenario 4 — Tasks and completion (US4)

```bash
php artisan test --compact --filter=PlanTask
```

**Manual walkthrough**:

1. Create a task with a date outside the plan's month; rejected (FR-032).
2. Move a task Pending → InProgress → Completed; each transition writes a `TaskStatusLog` row with
   actor, time, and note.
3. Confirm `completed_at` is set on completion.
4. Reopen the completed task; confirm `completed_at` clears and the plan's performance score is
   marked stale.
5. Attempt a rejected transition directly (e.g. `Cancelled → Completed`) and confirm it fails with a
   clear message even outside the UI.
6. Open the task list; confirm Overdue, Due soon, and Completed filters each show the right set.

---

## Scenario 5 — Visits and location (US5)

```bash
php artisan test --compact --filter=CustomerVisit
```

**Manual walkthrough**:

1. Open a `field`-recorded visit as a non-admin `employees.visit.review` holder; confirm the record
   is locked but the "Add / update review note" action still works.
2. Open the same visit as `System Admin`; confirm the record is editable via
   `employees.visit.field-edit`.
3. Update the review note twice; confirm the audit log shows both the old and new text for each
   update.
4. View a visit's GPS trail; confirm chronological order.
5. Complete a visit without a `checked_out_at`; confirm it later counts in the work-time denominator
   but not the numerator (see Scenario 7).

---

## Scenario 6 — Voice notes and AI (US6)

```bash
php artisan test --compact --filter=VoiceNote
```

**Manual walkthrough**:

1. Attach a voice note to a completed visit; confirm the visit remains `Completed` regardless of
   transcription outcome.
2. Using the fake driver, force a transcription failure; confirm `error_message` is set and nothing
   else changes.
3. Force a `DerivedFromLogProb` result; confirm the UI shows `≈ N%` with a tooltip, never labeled
   "provider-reported."
4. Force an `Unavailable` result; confirm the UI shows "Not reported by provider" and never `0.00%`.
5. Approve a sales-opportunity draft; confirm `reviewed_by`/`reviewed_at` are recorded and the
   decision is terminal.

---

## Scenario 7 — Performance and salary (US7)

```bash
php artisan test --compact --filter=PerformanceScoring
```

```bash
php artisan test --compact --filter=SalaryCalculation
```

**Manual walkthrough** — reproduce the reference case from
[contracts/performance-scoring.md](./contracts/performance-scoring.md):

1. Build a plan where an employee completes 10 tasks, 8 on or before `due_at`; confirm schedule
   adherence shows 80% and, at a schedule weight of 10, contributes a score of 8.00.
2. Open the performance preview; confirm every factor's source and weight are visible, including any
   zero-denominator factor's explicit "no data" marker.
3. Calculate salary for an employee using base salary, then for one using the commission/target
   amount; confirm both use the same formula and only differ in the base column.
4. Approve one bonus suggestion and leave another pending; confirm only the approved one is summed.
5. Change the plan and recalculate; confirm the prior calculation is marked superseded (never
   deleted) and the new one requires a fresh confirmation before it takes effect.

---

## Scenario 8 — Search, filter, and reports (US8, run last)

```bash
php artisan test --compact --filter=EmployeeReport
```

**Manual walkthrough**:

1. Search and filter each of the employee, plan, task, and visit lists.
2. Open each of the seven `EmployeeReportType` report views; confirm plan completion, overdue tasks,
   and unexecuted visits render.
3. Trigger a queued export; confirm it completes and downloads.

---

## Full gate

Before considering the feature done:

```bash
composer test
```

This mirrors `.github/workflows/tests.yml`. It must include `tests/Unit/ArchTest.php` passing
**without new exceptions added** — no class outside the `VoiceNoteTranscriber` driver namespace may
reference the OpenAI client, and no Filament resource may write a performance or salary row
directly.

```bash
vendor/bin/pint --dirty --format agent
```

```bash
vendor/bin/phpstan analyse
```

The PHPStan baseline may only shrink. New entries are forbidden; where this feature touches a file
with existing baseline entries, remove the ones that no longer apply.

---

## Reference

- Data model, columns, and relationships: [data-model.md](./data-model.md)
- Permission catalogue and fixed roles: [contracts/permissions.md](./contracts/permissions.md)
- Scoring and salary formulas: [contracts/performance-scoring.md](./contracts/performance-scoring.md)
- Status transitions and plan duplication: [contracts/plan-lifecycle.md](./contracts/plan-lifecycle.md)
- AI isolation and confidence handling: [contracts/voice-note-ai.md](./contracts/voice-note-ai.md)
- Dashboard surfaces and screen behaviors: [contracts/dashboard-ui.md](./contracts/dashboard-ui.md)
- Decision rationale: [research.md](./research.md)
