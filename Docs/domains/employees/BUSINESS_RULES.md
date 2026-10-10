# Employees Business Rules

---
status: canonical
owner: employees
last_verified: 2026-10-07
verified_against: employee services/enums/permissions/tests
---

## Plan / Task / Visit State

Sales Plan (`SalesPlanStatus`): Draft, Published, InProgress, Completed, Archived. Allowed transitions are Draft → Published → InProgress → Completed → Archived, with archival possible from Draft, Published, or InProgress as well. There is no Active or Paused enum value.

Task: Pending, In Progress, Completed, Cancelled.

Visit: Scheduled -> En Route -> In Progress -> Completed, with Unable to Complete or Cancelled terminal alternatives.

Transitions use domain services and are audited/tested rather than arbitrary field edits.

## Employee Access

Employee app/access enable/disable/archive/restore is managed through `EmployeeAccessService`.

## Visit Context and Follow-Up

A visit may reference serialized equipment only when that unit is currently in the same customer's custody. The visit cannot use equipment owned by another customer.

A visit follow-up remains an Employee `PlanTask`. Automatic follow-up creation preserves provenance to the source visit and, when available, the selected/reviewed Sales Opportunity, Quotation, converted Sales Order, and customer-owned serialized equipment. Follow-up tasks are excluded from the base plan-task completion denominator so they do not inflate the original monthly plan.

## Performance / Salary

Performance is calculated through `PerformanceScoringService`, then salary through dedicated calculation/recalculation services.

Salary calculations use Draft, Pending Confirmation, Confirmed and Superseded states. Confirmation is a distinct action.

The sales-opportunity factor counts only human-reviewed **Approved/Accepted** opportunities associated with the plan's visits. AI Draft and Rejected opportunities never increase performance or bonus calculations.

## Voice Notes / AI

Voice notes use Pending, Processing, Transcribed and Failed states; transcription records use Pending/Succeeded/Failed.

AI transcription is isolated behind transcriber contracts (including OpenAI Whisper and a fake implementation for tests). Keyword detection creates reviewable opportunity evidence without making an unreviewed business decision authoritative. Product detection may remain product-level when transcript evidence is insufficient to identify a specific variant.

AI/transcription failure must remain isolated from the underlying visit workflow.

## Permissions

Permissions separately cover employees, plans/tasks/visits, voice playback, AI rules/opportunity review, performance recalculation, salary calculation/confirmation, bonus approval, reports and audit.
