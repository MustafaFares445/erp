# Employees Workflows

---
status: canonical
owner: employees
last_verified: 2026-10-02
verified_against: employee services/tests
---

## Monthly Planning

`Employee -> Draft Sales Plan -> tasks -> Active -> execution -> Completed/Archived`

Plans can be duplicated to another employee/month through the dedicated duplication service.

## Task / Visit

`Plan Task Pending -> In Progress -> Completed/Cancelled`

Visits track their own Planned/In Progress/Completed/Missed state and GPS/media/review facts.

## Voice / AI

`Visit -> Voice Note intake -> queued transcription -> transcript -> keyword detection -> reviewable Sales Opportunity evidence`

The transcriber is replaceable; failure is recorded rather than allowed to corrupt visit state.

## Performance / Salary

`Plan execution facts -> performance score -> salary calculation/recalculation -> Pending Confirmation -> Confirmed`

Recalculation creates/supersedes calculation state through its services rather than rewriting confirmed history silently.
