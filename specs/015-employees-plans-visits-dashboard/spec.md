# Feature Specification: Employees, Monthly Plans, Visits, Performance & Salary Dashboard

**Feature Directory**: `015-employees-plans-visits-dashboard`

**Created**: 2026-08-05

**Status**: Draft

**Input**: Translate `IERP_Employees_Module_SRS.pdf` (Arabic, v2.0, dated 2026-08-04) into an English feature specification. The ten decisions (D1–D10) recorded in that SRS and in `specs/015-employees-plans-visits-dashboard/plan.md` §14 are project-owner approved and binding; this specification encodes them rather than reopening them. ADR 0003 (`Docs/adr/0003-filament-employees-dashboard.md`) authorises the dashboard scope this specification covers.

## Scope

This feature extends the existing `/admin` Filament dashboard for:

- employee profile, status, and salary-option maintenance;
- monthly plan management with evaluation-factor weights and their tasks;
- task tracking and completion history;
- visit, GPS-trail, and attachment review;
- voice-note and AI-transcription review and approval;
- performance scoring and salary/bonus calculation under documented rules;
- search, reporting, and audit review across all of the above;
- fixed dashboard roles and permissions for the module.

The feature does not add `/api/employee` endpoints, an employee mobile application, employee-app visit or attendance capture, mobile authentication flows, any other employee-facing API functionality, an attendance/shift/working-hours module, salary disbursement or accounting postings, or quotation/delivery creation during a visit. Any fully automated AI decision without human review is out of scope. Implementing an employee-facing API or mobile app later requires its own specification and either a separate ADR or an explicit amendment to ADR 0003.

## User Scenarios and Testing

### User Story 1 - Enforce Dashboard Roles and Permissions (Priority: P1)

An authorized dashboard user's access to every employee, plan, task, visit, AI-review, performance, salary, and report action is governed by one of four fixed roles, checked consistently everywhere an action can be triggered.

**Why this priority**: Every other story depends on permission checks being correct and consistently enforced; without this, no other capability can be trusted.

**Independent Test**: Sign in as each of the four fixed roles and exercise the same action (a direct page visit, a record action, a bulk action, and a direct service call bypassing the UI) to confirm identical allow/deny behavior across all four paths.

**Acceptance Scenarios**:

1. **Given** a System Admin, **when** using the dashboard, **then** full management, role management, exception approval, and restoration are available.
2. **Given** an Employee Manager, **when** using the dashboard, **then** employee profile, plan, and task management plus visit review are available, and role/salary/bonus actions are denied.
3. **Given** a Payroll Officer, **when** using the dashboard, **then** performance review, salary calculation, and bonus approval are available, and employee/plan/task management is denied.
4. **Given** a Reviewer, **when** using the dashboard, **then** all data, reports, and audit logs are viewable, and no create, edit, review-note, salary-confirmation, or bonus-approval action is available.
5. **Given** any role, **when** a page is opened or an action is executed (including a bulk action or a direct call that bypasses a hidden button), **then** the same permission boundary is enforced at both checkpoints.
6. **Given** an authorized reviewer holding `employees.visit.review` but not `employees.visit.field-edit`, **when** a field-recorded visit is open, **then** the review-note action remains available while the visit's recorded data stays locked.

---

### User Story 2 - Manage Employee Profiles (Priority: P1)

An Employee Manager creates and maintains employee profiles, including their salary basis, contact data, and app-access state, while preserving a searchable archive when an employee leaves.

**Why this priority**: Every plan, task, visit, and salary calculation is owned by an employee profile; nothing else in the module can exist without it.

**Independent Test**: Create, edit, search, filter, deactivate, and archive an employee profile without creating any plan, task, or visit.

**Acceptance Scenarios**:

1. **Given** no existing employee, **when** an Employee Manager creates one with a job title and contact data, **then** the system assigns a unique employee code, checked for uniqueness against archived employees too.
2. **Given** an employee whose base-salary option is disabled, **when** the profile is saved, **then** a commission/target amount is required as the payable base for performance calculations.
3. **Given** an existing employee, **when** their data is edited or their app access is enabled or disabled, **then** the change is saved and audited.
4. **Given** an employee with historical plans, tasks, or visits, **when** their account is deleted, **then** the account is archived rather than physically removed and its history remains intact.
5. **Given** a list of employees, **when** searched by code or name or filtered by status or job title, **then** matching results are returned with pagination.

---

### User Story 3 - Build and Maintain Monthly Plans (Priority: P1)

An Employee Manager creates a monthly plan for an employee, sets the four evaluation-factor weights and the required visit duration, and can copy a plan to another employee or month as an independent record.

**Why this priority**: The plan is the unit that scopes every task, every visit attribution, and the performance/salary calculation that follows; an incorrect plan invalidates everything measured against it.

**Independent Test**: Create a plan, adjust its weights and tasks, attempt an invalid save, copy it to another employee and to another month, and edit, deactivate, delete, and restore it — all without touching visits, AI review, or salary calculation.

**Acceptance Scenarios**:

1. **Given** a new plan, **when** it is saved, **then** it requires a name, a target month, and status, and it is rejected unless its four factor weights sum to exactly 100 and it has at least one task.
2. **Given** an employee who already has an active plan for a given month, **when** a second active plan is saved for the same employee and month, **then** the save is rejected.
3. **Given** an existing plan, **when** it is assigned ("copied") to a second employee, **then** an independent new plan record is created for the target employee — never a plan shared between employees — starting in Draft status with each task starting Pending, and no execution history (task status, completion timestamps, visit records, performance scores, salary calculations, or audit history) is copied.
4. **Given** a plan copy where the target employee already has an active plan for the target month, **then** the copy is rejected with a message naming the conflicting plan, before any row is written.
5. **Given** a plan copied to a new month, **when** task dates are rebased, **then** each date's day-of-month offset is preserved except when the target month is shorter, in which case the date clamps to the target month's last day rather than rolling into the following month.
6. **Given** a plan copy, **when** it executes, **then** the plan and all of its tasks are created together in one operation, or none of them are created.
7. **Given** an existing plan, **when** it is edited, deactivated, deleted, or restored, **then** deletion is only permitted while no employee has completed any task on that plan.
8. **Given** a plan's required-visit-duration field, **when** it is left blank, **then** the system-wide default duration applies for that plan's work-time adherence calculation.

---

### User Story 4 - Track Tasks Through Completion (Priority: P1)

An Employee Manager creates tasks inside a plan with mandatory start and end dates, optionally links a task to a customer, and the system records every status change with actor, time, and note.

**Why this priority**: Task completion and its timing are the direct input to both the task score and the schedule-adherence score; without accurate task tracking, performance and salary cannot be calculated.

**Independent Test**: Create tasks inside a plan, move them through valid and invalid status transitions, reopen a completed task, and view overdue/near-due/completed lists — all without creating a visit or running a salary calculation.

**Acceptance Scenarios**:

1. **Given** a plan, **when** a task is created, **then** a title, description, start date, and end date are all required, and the task's dates must fall within the plan's own month window.
2. **Given** a task, **when** it is optionally linked to a customer, **then** the link is stored and displayed.
3. **Given** a task status change, **when** it occurs, **then** the actor, timestamp, and an optional note are recorded in an append-only status history.
4. **Given** a task list, **when** viewed, **then** overdue, near-due, and completed tasks are each identifiable.
5. **Given** a task entering Completed status, **when** the transition happens, **then** a completion timestamp is recorded; **given** that same task is reopened to InProgress, **when** the transition happens, **then** the completion timestamp is cleared and the plan's performance score is marked stale.
6. **Given** the documented transition table, **when** a disallowed transition is attempted (directly or through the UI), **then** it is rejected with a clear message, and a transition to a task's current status is always rejected.

---

### User Story 5 - Review Visits and Location Trail (Priority: P2)

An authorized reviewer sees an employee's planned and executed visits linked to their task and customer, including check-in/check-out times, the GPS trail, and attachments, and can add a review note without altering a field-recorded visit's data.

**Why this priority**: Visit completion and duration feed directly into the visit and work-time performance scores, and the review note is the dashboard's only way to close the loop on field activity that this feature does not capture directly.

**Independent Test**: View a visit's timeline, GPS trail, and attachments; attempt to edit a field-recorded visit as a non-admin reviewer and confirm it is rejected while the review-note action still succeeds; attempt the same edit as a System Admin and confirm it succeeds.

**Acceptance Scenarios**:

1. **Given** an employee's visits, **when** viewed, **then** each visit shows its linked task and customer, planned vs. executed state, check-in/check-out times, computed duration, and outcome.
2. **Given** a visit, **when** its GPS records are viewed, **then** they are shown in chronological order.
3. **Given** a visit, **when** its attachments are viewed, **then** images and files are shown and any audio is playable through a temporary signed link rather than a public path.
4. **Given** a visit recorded in the field, **when** a non-admin user attempts to edit its recorded data, **then** the edit is rejected, while the review-note action remains available to an authorized reviewer on that same visit.
5. **Given** a visit, **when** a review note is created or updated, **then** the visit stores exactly one current review note with its author and timestamp, and every create/update of that note is written to the audit log with its old and new text, so the audit trail is the note's full revision history.
6. **Given** a completed visit missing a check-in or check-out time, **when** work-time adherence is calculated, **then** that visit counts toward the denominator but not the numerator, because its duration cannot be verified.

---

### User Story 6 - Review Voice Notes and AI Transcripts (Priority: P2)

An authorized reviewer sees each visit's voice notes, their transcription text, a confidence indicator with its source, and any AI-drafted sales opportunity, and no AI output takes effect without an explicit human decision.

**Why this priority**: The AI pipeline is a convenience layered on top of visit review; it must never block a visit or silently influence performance or pay, so its review workflow is delivered as its own testable slice.

**Independent Test**: Trigger transcription of a voice note (success and induced-failure cases) using a fake, network-free driver; verify confidence display and labeling, keyword-rule management, and opportunity-draft approval/rejection — all without affecting visit completion, performance, or salary.

**Acceptance Scenarios**:

1. **Given** a voice note linked to a visit, **when** viewed, **then** its processing status is shown, and once processed, the extracted text, a 0–100 confidence value, its source label, and any failure reason are shown.
2. **Given** transcription succeeds with a provider-reported confidence, **when** displayed, **then** it is labeled as coming from the provider; **given** confidence is only derivable from log-probabilities, **when** displayed, **then** it is labeled as derived, not provider-reported; **given** neither is available, **when** displayed, **then** no numeric value is stored or shown and it is labeled unavailable — never shown as `0`.
3. **Given** a transcription failure, **when** it occurs, **then** the visit remains completable and no performance or salary calculation is affected.
4. **Given** recordings in English, Arabic, mixed Arabic/English, Arabic dialects, or varied English accents, **when** transcribed, **then** the system attempts transcription for all of them, with lower accuracy for dialects and strong accents documented as a known limitation rather than a rejected input.
5. **Given** keyword rules linked to products or product variants, **when** managed, **then** the links are saved and a rule with no product link remains a valid text-only match.
6. **Given** a draft sales opportunity, **when** an authorized reviewer approves or rejects it, **then** the decision, reviewer, and time are recorded, and no draft takes effect without that recorded decision.

---

### User Story 7 - Calculate Performance and Salary (Priority: P1)

A Payroll Officer sees each plan's task, visit, schedule, and work-time scores, their weights, and the resulting total score, previews the salary calculation with any admin-approved bonus, and confirms it.

**Why this priority**: This is the module's financial payoff — turning tracked task and visit activity into a defensible salary figure — and it is the most rule-heavy, highest-risk calculation in the feature.

**Independent Test**: Using a plan with a mix of on-time, late, and reopened tasks, and visits with and without verifiable duration, calculate performance and salary, change the plan, and confirm the recalculation and supersession behavior, all independent of the AI review workflow.

**Acceptance Scenarios**:

1. **Given** a plan's tasks and visits, **when** performance is calculated, **then** task, visit, schedule, and work-time scores are each computed from that data alone, weighted per the plan's four factors, and summed into a total score between 0 and 100.
2. **Given** a performance preview, **when** viewed, **then** the source and weight of each component score are shown.
3. **Given** a plan where 8 of 10 completed tasks finished on or before their due date, **when** schedule adherence is calculated, **then** it is 80%, and at a schedule weight of 10 the schedule score is 8.00.
4. **Given** any of the four scoring factors with zero completed items in its denominator, **when** calculated, **then** that factor's score is 0, its weight is not redistributed to the other factors, and the zero-denominator condition is recorded in the calculation breakdown.
5. **Given** an employee with base salary enabled, **when** salary is calculated, **then** the payable base is the base salary; **given** base salary disabled, **then** the payable base is the commission/target amount; **given** either required value is missing, **then** the calculation is rejected as a validation error rather than treated as zero.
6. **Given** a payable base, a total score, and approved bonuses, **when** salary is calculated, **then** final salary equals payable base times (total score ÷ 100) plus the sum of approved bonuses only, rounded once at the end.
7. **Given** the task-completion ratio (completed ÷ total plan tasks), **when** shown, **then** it is displayed as a statistic and does not itself determine salary; only the weighted total score determines salary.
8. **Given** a confirmed salary calculation, **when** its plan changes and salary is recalculated, **then** the admin is notified of the change before confirming, the prior calculation is marked superseded rather than deleted, and the new calculation requires its own recorded confirmation before it takes effect.
9. **Given** bonus suggestions for an employee and plan, **when** salary is calculated, **then** only approved bonuses contribute; pending and rejected suggestions contribute nothing.

---

### User Story 8 - Search, Filter, and Report Across the Module (Priority: P3)

Any authorized dashboard user searches and filters across employees, plans, tasks, and visits, and views plan completion, overdue tasks, unexecuted visits, and performance/salary summaries by employee or month.

**Why this priority**: Reporting and search add day-to-day usability on top of the core record-keeping and calculation delivered by the earlier stories, without which this data would still exist but be hard to act on.

**Independent Test**: Search and filter each of the four core record types, and open each report view, using data already created by the earlier stories.

**Acceptance Scenarios**:

1. **Given** the employee, plan, task, and visit lists, **when** searched or filtered, **then** matching results are returned with pagination.
2. **Given** the reporting views, **when** opened, **then** plan completion percentages, overdue tasks, and unexecuted visits are shown.
3. **Given** the reporting views, **when** opened, **then** performance and salary figures can be viewed by employee or by month.

## Edge Cases

- A visit not linked to any plan task is excluded from both the numerator and denominator of task-driven scoring, and its count is recorded in the calculation breakdown so the preview screen shows that work happened outside the plan rather than silently dropping it.
- All four zero-denominator cases (no completed tasks, no completed/planned visits, no completed tasks for schedule adherence, no completed visits for work-time adherence) score 0 for that factor without ever dividing by zero.
- The required visit duration actually used for a historical performance calculation is snapshotted in that calculation's breakdown, so a later change to the plan's duration or the system-wide default never changes a past score.
- A month-end plan copy (e.g., a task due January 31st copied into February) clamps to February's last day rather than rolling into March.
- Restoring a deleted employee, plan, or salary calculation returns it to its archived/superseded state, never directly back to active/confirmed.
- A rejected sales-opportunity draft or bonus suggestion is a final state; changing the underlying decision requires a new draft or suggestion rather than reopening the rejected one.
- Overwriting a visit's review note does not lose the prior text: every create or update is captured in the audit log with old and new values.
- An unauthorized direct service call (bypassing a hidden or disabled UI control) is rejected the same way the equivalent UI action would be.

## Functional Requirements

### Roles and Authorization

- **FR-001**: The System Admin role MUST have full management access, role management, exception approval, and restoration capability.
- **FR-002**: The Employee Manager role MUST be able to manage employee profiles, plans, and tasks, and review visits.
- **FR-003**: The Payroll Officer role MUST be able to review performance, calculate salaries, and approve bonuses.
- **FR-004**: The Reviewer role MUST be able to view data, reports, and audit logs with no edit, review-note, salary-confirmation, or bonus-approval capability.
- **FR-005**: The system MUST check permission both when a page is opened and when each action is executed.
- **FR-006**: Hiding a control MUST NOT be relied upon as the only means of preventing an action.
- **FR-007**: Bulk actions MUST be subject to the same permission checks as the equivalent individual action.
- **FR-008**: The system MUST validate every status transition inside a domain service and reject a disallowed transition with a clear message, even when invoked directly rather than through the UI.

### Employee Profiles

- **FR-010**: The system MUST support creating an employee with a unique code, job title, and contact data.
- **FR-011**: The system MUST calculate salary from monitored task and visit data to produce the final monthly salary.
- **FR-012**: The system MUST support editing employee data and enabling or disabling their app access.
- **FR-013**: Deleting an employee account MUST archive it rather than physically remove it, retaining its data.
- **FR-014**: The system MUST support searching employees by code or name and filtering by status and job title.

### Monthly Plans

- **FR-020**: The system MUST support creating a monthly plan with a name, month, and status; assigning "the same plan" to a different employee MUST create an independent new plan record owned by the target employee, never a plan shared between employees.
- **FR-021**: The system MUST support setting weights for the task, visit, schedule-adherence, and work-time-adherence factors.
- **FR-022**: The system MUST reject saving a plan unless its four factor weights sum to exactly 100 and it has at least one task.
- **FR-023**: The system MUST reject more than one active plan for the same employee in the same month.
- **FR-024**: The system MUST support copying a prior month's plan, with its tasks, into a new month, rebasing each task's date offset onto the target month.
- **FR-025**: The system MUST support editing, deactivating, deleting, and restoring a plan; deletion MUST be permitted only while no employee has completed any task on it.
- **FR-026**: The system MUST support setting a plan-level required visit duration that work-time adherence is measured against, falling back to a system-wide default when left blank.

### Tasks and Completion Tracking

- **FR-030**: The system MUST support creating a task inside a plan with a title, description, start date, and end date; both dates are required because schedule adherence needs a due date on every task.
- **FR-031**: The system MUST support linking a task to a customer when applicable.
- **FR-032**: A task's dates MUST fall within its plan's month window, never before or after it.
- **FR-033**: The system MUST log every task status change with its actor, timestamp, and note.
- **FR-034**: The system MUST distinguish overdue, near-due, and completed tasks in task views.
- **FR-035**: The system MUST record a task's completion timestamp when it enters Completed status and clear it when the task is reopened.

### Visits and Location

- **FR-040**: The system MUST show an employee's planned and executed visits linked to their task and customer.
- **FR-041**: The system MUST show check-in time, check-out time, computed visit duration, and outcome.
- **FR-042**: The system MUST show a visit's GPS records in chronological order.
- **FR-043**: The system MUST show a visit's image and file attachments.
- **FR-044**: The system MUST prevent editing a field-recorded visit's data except by a System Admin, while keeping the review-note action available to an authorized reviewer on that same visit.
- **FR-045**: The system MUST store exactly one review note per visit, with reviewer and timestamp, on the visit record itself, and MUST write every create or update of that note to the central audit log with old and new values.

### Voice Notes and AI

- **FR-050**: The system MUST show voice notes linked to visits and their processing state.
- **FR-051**: The system MUST show extracted transcript text, a 0–100 confidence value, its source label, and the failure reason when present.
- **FR-052**: The system MUST support managing keyword rules and linking them to products and product variants.
- **FR-053**: The system MUST show draft sales opportunities.
- **FR-054**: No AI output MUST be approved automatically without an authorized human review.
- **FR-055**: Transcription MUST support English, Arabic, mixed Arabic/English, Arabic local dialects, and varied English accents.
- **FR-056**: When the transcription provider reports no reliable confidence, the system MUST store no confidence value and label it as unavailable, and MUST NEVER display a fabricated figure as provider-reported.

### Performance and Salary

- **FR-060**: The system MUST calculate task, visit, schedule, and work-time scores plus a total score per plan, using only existing task and visit data.
- **FR-061**: The system MUST show the source of each score and the weights used on a preview screen.
- **FR-062**: The system MUST calculate salary from the optional base salary, performance percentage, and bonus.
- **FR-063**: The system MUST show the performance percentage; the completed-to-total task ratio MUST be displayed as a statistic while the weighted total score determines pay.
- **FR-064**: The system MUST show bonus suggestions with reasons, requiring a recorded admin decision before approval takes effect.
- **FR-065**: When a plan changes, the system MUST recalculate salary against the new plan, notify the admin of the change before confirmation, mark the prior calculation superseded rather than deleting it, and require a fresh recorded confirmation before the new calculation takes effect.
- **FR-066**: Schedule adherence MUST equal completed tasks finished on or before their due date, divided by total completed tasks.
- **FR-067**: Work-time adherence MUST equal completed visits meeting the required duration, divided by total completed visits, where duration equals check-out time minus check-in time.

### Search, Reports, and Audit

- **FR-070**: The system MUST support search and filtering across employees, plans, tasks, and visits.
- **FR-071**: The system MUST show plan completion percentages, overdue tasks, and unexecuted visits.
- **FR-072**: The system MUST show performance and salary figures by employee or by month.

### General

- **FR-080**: The system MUST present clear validation and error messages.
- **FR-081**: Pages and actions MUST be protected by permission checks.
- **FR-082**: Sensitive operations MUST run safely with no partial save.
- **FR-083**: Voice-note audio files MUST be stored and playable.
- **FR-084**: The system MUST maintain a reviewable audit trail.
- **FR-085**: Lists MUST support search, filtering, and pagination.

## Key Entities

- **Employee Profile**: A user's employment record — code, job title, contact data, salary option, and active/archived state.
- **Monthly Plan**: An employee's plan for a given month, carrying the four evaluation-factor weights, required visit duration, and status; each plan belongs to exactly one employee.
- **Plan Task**: A task inside a plan with a title, description, mandatory date window, optional customer link, and status.
- **Task Status Log**: An append-only record of a task's status changes, with actor, timestamp, and note.
- **Customer Visit**: A planned or executed visit linked to a task and customer, with check-in/check-out times, outcome, a single review note, and its recording channel (dashboard or field).
- **Visit GPS Log**: An append-only, chronologically ordered location record for a visit.
- **Employee Voice Note**: A voice recording linked to a visit, with its processing status and audio media.
- **Voice Note Transcription**: The transcript, confidence value, confidence source, detected language, and failure reason for a voice note.
- **AI Keyword Rule**: A keyword-matching rule, optionally linked to a product or product variant, used to surface sales opportunities.
- **Sales Opportunity Draft**: An AI-drafted opportunity awaiting an authorized reviewer's approval or rejection.
- **Employee Performance Score**: The task, visit, schedule, and work-time component scores, their weights, the total score, and the calculation breakdown for one plan.
- **Employee Salary Calculation**: A payable-base, performance-percentage, bonus, and final-salary record for one plan, with a status that tracks confirmation and supersession.
- **Bonus Suggestion**: A proposed bonus amount and reason for an employee and plan, awaiting admin approval or rejection.
- **Employee Report**: A read-only aggregate view (plan completion, overdue tasks, unexecuted visits, performance/salary by employee or month) with no backing table of its own.

## Assumptions and Dependencies

- The existing `/admin` Filament panel, Spatie Permission, Spatie Media Library, and central audit-log infrastructure remain canonical and are extended, not duplicated, by this feature.
- No attendance, shift, or working-hours module exists or is added; the schedule and work-time performance factors are derived only from task due dates and visit check-in/check-out timestamps that this feature already owns.
- Visit and GPS capture are produced by the out-of-scope employee mobile app; this feature treats visits, GPS logs, and voice notes as read, review, and administer surfaces, though the dashboard may create a dashboard-originated visit record.
- Voice-note transcription is provided by a single external AI provider reached through an internal abstraction; no test in this feature reaches the network, and a network-free fake driver is used instead.
- All dashboard labels, validation messages, and reports for this feature are delivered in English only for this phase, matching spec 013's precedent.
- Salary calculation produces a figure for review and record-keeping only; disbursing pay and posting it to accounting ledgers remain outside this feature.
- The ten decisions (D1–D10) recorded in `specs/015-employees-plans-visits-dashboard/plan.md` §14 are already approved and are treated as settled inputs to this specification, not open questions.

## Success Criteria

- **SC-001**: An Employee Manager can create an employee profile with a guaranteed-unique code in under two minutes, with no two profiles ever able to share a code, including against archived employees.
- **SC-002**: An Employee Manager can build a valid monthly plan — weights summing to exactly 100, at least one task — in under five minutes, with every invalid weight combination rejected before save.
- **SC-003**: Copying a plan to another employee or month never leaves partial data: either the plan and all its tasks exist afterward or none of them do, and a copy targeting an employee with an existing active plan for that month is always rejected before any row is written.
- **SC-004**: Every completed task and completed visit contributes to schedule and work-time adherence exactly per the documented formulas, and every zero-denominator case scores 0 without a calculation error.
- **SC-005**: A Payroll Officer can see the source and weight of every performance factor and the resulting salary figure on one preview screen before confirming, for 100% of calculated plans.
- **SC-006**: No AI transcription confidence value is ever displayed as a fabricated or provider-reported number when it is actually derived or unavailable.
- **SC-007**: A field-recorded visit is never editable by a non-admin user, while an authorized reviewer can always add or update its review note, verified across 100% of tested role/action combinations.
- **SC-008**: Every sensitive action — employee archive/restore, plan mutation, task transition, visit review note, salary confirmation, bonus decision, and role assignment — produces a retrievable audit entry with actor and timestamp.
- **SC-009**: Every one of the four fixed roles is denied on 100% of actions outside its matrix and permitted on 100% of actions inside it, verified at both page-open and action-execution checkpoints.
- **SC-010**: The `composer test` quality gate completes successfully without lowering the project's existing 100% type-coverage or code-coverage thresholds or growing the PHPStan baseline.
