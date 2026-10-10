# Plan Tasks

## When to use this page

Use this screen to review its available records and supported actions.

## Typical tasks

- Follow the documented “Task handling” instructions below.
- Follow the documented “Task queue” instructions below.
- Follow the documented “Review task” instructions below.

<IerpNavigationPath :items='["Employees","Planning","Tasks"]' />

Tasks organize work assigned through the monthly plan and the follow-ups produced by employee visits. Task completion feeds performance only according to the plan's own scoring and provenance rules.

## Task handling

1. In the list, search by title/plan and filter **Overdue**, **Due soon** or **Completed**. Inspect due date, status and completion timestamp.
2. Open **View** to verify plan association, the work expected and any visit-derived follow-up provenance.
3. The lifecycle is **Pending → In Progress → Completed**, with **Cancelled** as an allowed alternative. Use **Start progress**, **Complete** or **Cancel** with a required note and confirmation.
4. A completed task can be **Reopened** only with the required permission. Reopening clears its completion timestamp and marks the plan performance score stale for recalculation.
5. Use **Edit** for permitted changes to task details. Directly setting a state without the domain transition service can lose audit evidence.
6. A follow-up task generated from a visit must not increase the original plan's base-task denominator.

## Task queue

<IerpScreenshot id="employees-task-index" title="Plan tasks worklist" description="Search and filter tasks by due date, completion and plan." path="Employees → Tasks" />

## Review task

<IerpScreenshot id="employees-task-view" title="Task details" description="Confirm task identity, due date, status and linked monthly plan." path="Employees → Tasks → View" />

## Edit eligible task

<IerpScreenshot id="employees-task-edit" title="Task edit form" description="Change permitted planning fields without bypassing status transitions." path="Employees → Tasks → Edit" imageSrc="/images/en/employees/employees-task-edit.png" />

**Do not mark completion by editing only the timestamp.** The transition actions preserve the note, audit history and performance recalculation behavior. A missing action usually reflects the task's current status or update permission.

Related: [Monthly Plans](/screens/employees/monthly-plan), [Visits](/screens/employees/visit).
