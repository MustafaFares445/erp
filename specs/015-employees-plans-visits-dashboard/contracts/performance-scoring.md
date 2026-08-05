# Contract: Performance Scoring and Salary Calculation

**Source of truth**: `PerformanceScoringService` (pure, deterministic) and `SalaryCalculationService`.
Settles D2, D3, D4, D5.

## Scoring formula

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

# D4: the four weights sum to exactly 100, so total_score is always a 0..100 percentage.

# D2: total_score is the figure that drives pay.
performance_percent      = total_score
# FR-063 statistic, displayed but never payable:
task_completion_percent  = task_completion × 100
```

**Worked example** (the approved reference case): an employee completed 10 tasks and 8 of them were
completed on or before their `due_at` → `schedule_adherence = 0.80`. With `schedule_weight = 10`,
`schedule_score = 8.00`.

## Definitions and edge cases

- **On time** means `completed_at <= due_at`, inclusive — equality counts as on time.
- The schedule denominator is tasks in `Completed` status only. `Cancelled`, `Pending`, and
  `InProgress` tasks are outside both numerator and denominator: this factor measures how punctually
  finished work was finished, not how much was finished (that is `task_completion`).
- `due_at` is `NOT NULL` on every task, so no completed task can drop out of the numerator for
  missing data.
- Reopening a task clears `completed_at` and removes it from the schedule denominator until it is
  completed again.
- **Visit-to-plan attribution**: a visit belongs to a plan only through
  `plan_task_id → plan_tasks.sales_plan_id`. A visit with a null `plan_task_id` is excluded from both
  the numerator and denominator of `visit_completion` and `work_time_adherence`; its count is
  recorded in `calculation_breakdown` so the preview screen can show that work happened outside the
  plan instead of silently ignoring it.
- The work-time denominator is plan-attributed visits in `Completed` status. A completed visit
  missing `checked_in_at` or `checked_out_at` counts in the denominator but **not** the numerator —
  its duration cannot be verified. Its count is recorded in `calculation_breakdown`.
- The effective `required_duration` actually used is snapshotted into `calculation_breakdown`; a
  later plan edit or config change never alters a historical score.
- Both D5 factors read only tables this feature already owns. No attendance, shift, or
  working-hours table exists (see `research.md` R-007).

## Zero-denominator rule

Applies independently to all four factors: when a denominator is 0 (no tasks, no visits, no
completed tasks, no completed visits), that factor scores **0**, its weight is **not** redistributed
to the other three factors, and the zero-denominator condition is recorded explicitly in
`calculation_breakdown`. Never divide by zero.

## Salary formula (D2, D3)

```text
payable_base =
    use_base_salary ? employee.base_salary                # BasePlusPerformance
                    : employee.commission_target_amount   # PerformanceOnly (D3)

final_salary = payable_base × (performance_percent / 100) + bonus_amount
             = payable_base × (total_score        / 100) + bonus_amount
```

Both modes share one formula and differ only in which column supplies `payable_base` — no divergent
code path per mode.

## Salary rules

- `payable_base` is **required**: `base_salary` must be non-null when `use_base_salary = true`, and
  `commission_target_amount` must be non-null when it is false. A null payable base is a validation
  failure, never a silent 0, and the validation message names which specific field (`base_salary` or
  `commission_target_amount`) is missing, so it is distinguishable from every other validation
  failure in this module.
- `bonus_amount` is the sum of `bonus_suggestions` in `Approved` state for that employee and plan.
  `Pending` and `Rejected` suggestions contribute nothing.
- Compute in `decimal(15,2)`; round half-up once at the end, never on intermediate factors.
- The row records `payable_base`, `performance_percent`, `bonus_amount`, and `final_salary`, so the
  figure is reproducible from the row alone without re-reading the employee profile, which may
  change later.
- On plan change: recalculate, notify the admin before confirmation, mark the prior calculation
  `Superseded` (never delete it), and require a fresh recorded confirmation before the new
  calculation takes effect (FR-065; see [plan-lifecycle.md](./plan-lifecycle.md) for the status
  transitions).

## Guarantees

- `PerformanceScoringService` is pure and unit-testable without a database connection.
- Every one of the four zero-denominator cases scores 0 without a division-by-zero error and without
  redistributing weight.
- `total_score` always equals `performance_percent` (D2).
- A null payable base always fails validation; it is never silently treated as 0.

## Verification

- Table-driven unit tests: zero tasks, all complete, partial completion for each factor
  independently.
- The D5 worked example (8 of 10 tasks on time → 80%) reproduces exactly.
- `completed_at == due_at` counts as on time.
- A completed visit missing `checked_out_at` counts in the denominator only.
- Effective `required_visit_minutes` resolves from the plan, then from config, and is snapshotted.
- Each of the four factors independently exercises a zero-denominator case.
- The non-redistribution test uses a plan whose four weights sum to exactly 100 (D4-compliant); with
  one factor's denominator forced to 0, `total_score` must equal exactly `100 − <that factor's
  weight>`, proving no redistribution occurred independently of D4's own weight-sum test.
- Rounding is verified at `decimal(5,2)` boundaries.
- Both salary modes resolve `payable_base` from the correct column; a null payable base is rejected.
- Only `Approved` bonus suggestions are summed into `bonus_amount`.
