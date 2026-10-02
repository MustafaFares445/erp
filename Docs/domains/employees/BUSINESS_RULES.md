# Employees Business Rules

---
status: canonical
owner: employees
last_verified: 2026-10-02
verified_against: employee services/enums/permissions/tests
---

## Plan / Task / Visit State

Sales Plan: Draft, Active, Paused, Completed, Archived.

Task: Pending, In Progress, Completed, Cancelled.

Visit: Planned, In Progress, Completed, Missed.

Transitions use domain services and are audited/tested rather than arbitrary field edits.

## Employee Access

Employee app/access enable/disable/archive/restore is managed through `EmployeeAccessService`.

## Performance / Salary

Performance is calculated through `PerformanceScoringService`, then salary through dedicated calculation/recalculation services.

Salary calculations use Draft, Pending Confirmation, Confirmed and Superseded states. Confirmation is a distinct action.

## Voice Notes / AI

Voice notes use Pending, Processing, Transcribed and Failed states; transcription records use Pending/Succeeded/Failed.

AI transcription is isolated behind transcriber contracts (including OpenAI Whisper and a fake implementation for tests). Keyword detection creates/reviews opportunity evidence without making an unreviewed business decision authoritative.

AI/transcription failure must remain isolated from the underlying visit workflow.

## Permissions

Permissions separately cover employees, plans/tasks/visits, voice playback, AI rules/opportunity review, performance recalculation, salary calculation/confirmation, bonus approval, reports and audit.
