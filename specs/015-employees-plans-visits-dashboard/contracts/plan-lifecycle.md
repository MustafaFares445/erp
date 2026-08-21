# Contract: Status Transitions and Plan Duplication

**Source of truth**: each status enum's `allowedTransitions(): array` and
`canTransitionTo(self $to): bool`; every domain service calls the guard before writing. Settles D8
(status vocabularies) and D9 (plan duplication).

**Rule (FR-008)**: every domain service validates a transition inside itself, never relying on a
hidden or disabled UI control alone. A rejected transition throws
`App\Services\Employees\Exceptions\InvalidStatusTransition`, rendered by Filament as a clear
validation message (FR-080). **Self-transitions are rejected everywhere.** Terminal states accept
no transition at all.

## `SalesPlanStatus`

| From | Allowed to | Guard |
|---|---|---|
| `Draft` | `Active`, `Archived` | → `Active` requires the four weights to sum to exactly 100, ≥1 task, and no other `Active` plan for that employee/month |
| `Active` | `Paused`, `Completed` | |
| `Paused` | `Active`, `Archived` | → `Active` re-runs the one-active-plan guard |
| `Completed` | `Archived` | |
| `Archived` | — | terminal; soft-delete restore returns a plan to `Archived`, never to `Active` |

Rejected: `Draft → Paused`, `Draft → Completed`, `Active → Draft`, `Completed → Active`,
`Completed → Paused`, anything from `Archived`.

## `PlanTaskStatus`

| From | Allowed to | Guard |
|---|---|---|
| `Pending` | `InProgress`, `Completed`, `Cancelled` | |
| `InProgress` | `Completed`, `Cancelled`, `Pending` | |
| `Completed` | `InProgress` | reopen; requires `employees.task.manage`; clears `completed_at`; marks the plan's performance score stale |
| `Cancelled` | `Pending` | reinstate |

Rejected: `Completed → Cancelled`, `Cancelled → Completed`, `Cancelled → InProgress`.

## `VisitStatus`

| From | Allowed to | Guard |
|---|---|---|
| `Planned` | `InProgress`, `Missed` | |
| `InProgress` | `Completed`, `Missed` | → `Completed` requires `checked_out_at` |
| `Completed` | — | terminal |
| `Missed` | `Planned` | reschedule |

Rejected: `Planned → Completed` (must check in first), anything from `Completed`,
`Missed → InProgress`, `Missed → Completed`.

## `VoiceNoteStatus`

| From | Allowed to | Guard |
|---|---|---|
| `Pending` | `Processing` | job picked up |
| `Processing` | `Transcribed`, `Failed` | |
| `Failed` | `Pending` | manual retry, bounded by the retry policy in [voice-note-ai.md](./voice-note-ai.md) |

Rejected: `Pending → Transcribed`, `Processing → Pending`, anything from `Transcribed`.

## `TranscriptionStatus`

| From | Allowed to |
|---|---|
| `Pending` | `Succeeded`, `Failed` |
| `Failed` | `Pending` (retry) |
| `Succeeded` | — terminal |

## `OpportunityDraftStatus`

| From | Allowed to | Guard |
|---|---|---|
| `Draft` | `Approved`, `Rejected` | requires `employees.opportunity.review` and a recorded `reviewed_by`/`reviewed_at` |

`Approved` and `Rejected` are terminal — a superseded decision means creating a new draft, so no
decision is ever silently rewritten (FR-054).

## `BonusSuggestionStatus`

| From | Allowed to | Guard |
|---|---|---|
| `Pending` | `Approved`, `Rejected` | requires `employees.bonus.approve` and a recorded `approved_by`/`approved_at` |

`Approved` and `Rejected` are terminal (FR-064).

## `SalaryCalculationStatus`

| From | Allowed to | Guard |
|---|---|---|
| `Draft` | `PendingConfirmation` | |
| `PendingConfirmation` | `Confirmed`, `Superseded` | → `Confirmed` requires `employees.salary.confirm` |
| `Confirmed` | `Superseded` | only via a recalculation that sets `superseded_by_id` |
| `Superseded` | — | terminal |

Rejected: `Draft → Confirmed`, `Confirmed → Draft`, `Confirmed → PendingConfirmation`, anything from
`Superseded`.

---

## Plan duplication contract (D9)

`SalesPlanDuplicationService` serves both FR-024 (copy to a new month) and FR-020 (assign to
another employee). It creates **one new, independent `sales_plans` row** — there is no shared or
many-to-many plan model.

**Copied**: plan name; target month; the four factor weights; `required_visit_minutes` and any other
plan-level configuration; plan tasks with their titles and descriptions; customer associations where
the customer is still active; task date offsets rebased onto the target month (see `research.md`
R-004 for the month-length clamping algorithm).

**Not copied**: task execution statuses (every copied task starts `Pending`); `completed_at`; task
status history; visit execution records; performance calculations; salary calculations; bonus
decisions; audit records.

**The copy owns its own** `employee_id`, `status` (starts `Draft`), tasks, performance score, and
salary calculations.

### Rules

- The new plan is created as `Draft`, so activation runs the one-active-plan guard as a second line
  of defense.
- **Reject the operation** when the target employee already has an `Active` plan for the target
  month, with a clear validation message naming the conflicting plan (FR-023, FR-080). This check
  runs before any row is written.
- Date rebasing: each task's offset from the *source* month's first day is applied to the *target*
  month's first day. When the target month is shorter, the resulting date is **clamped to the target
  month's last day** — a task due on 31 January copied into February lands on 28 (or 29) February,
  never spills into March. Both `starts_at` and `due_at` are rebased, and the result must still
  satisfy FR-032 (inside the plan window).
- The whole copy runs in one transaction: either the plan and all its tasks exist, or none do
  (FR-082).
- The copy is audited as a single `plan.copied` entry recording the source plan, target employee, and
  target month.

## Guarantees

- Every allowed transition succeeds and every rejected transition fails with a clear message,
  including when called directly, bypassing the UI entirely.
- A plan copy either fully exists or does not exist at all — never partially.
- A plan copy targeting an employee with an existing active plan for that month never writes any
  row.
- Month-end clamping never produces a date outside the target plan's own month.

## Verification

- Enum unit tests: every allowed transition accepted, every rejected transition refused, including
  self-transitions, for all eight stateful enums above.
- Plan-copy feature tests: every copied field is present and every excluded field is absent; copied
  tasks start `Pending`; the new plan starts `Draft`; rejected when the target has an active plan
  that month; month-length clamping (31 Jan → 28/29 Feb); the whole copy rolls back as one
  transaction on any failure.
- Policy tests: a rejected state transition fails in the service even when the UI is bypassed
  entirely (FR-005–FR-008).
