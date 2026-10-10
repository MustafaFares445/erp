# Service records: technician execution and QA

<IerpPageSummary purpose="Review and perform the individual technician tasks linked to maintenance, with recorded work, parts, quality checks and cost evidence." useWhen="A technician needs to execute an approved job or a manager needs to verify service evidence." owner="Support and Maintenance" />

## When to use this page

A technician needs to execute an approved job or a manager needs to verify service evidence.

## Typical tasks

- Follow the documented “Scope and queue” instructions below.
- Follow the documented “Inspect the work” instructions below.
- Follow the documented “Edit and finish” instructions below.

## Scope and queue

A Service Record is a task attached to a **Maintenance Request**. The standalone list is for searching and managing existing tasks: **new tasks are created from the parent maintenance request**, not as orphaned records. Confirm the customer, equipment, job authorization, assigned employee and chargeability before starting.

<IerpScreenshot id="support-service-record-index" title="Service task queue" description="Search across maintenance requests and focus open, in-progress or completed technician tasks." path="Support and Maintenance → Service Records" imageSrc="/images/en/support/support-service-record-index.png" />

Search, sort and filter only narrow the list; they do not change technician assignments, stock or customer balances. Check due dates separately from actual completion evidence.

## Inspect the work

<IerpScreenshot id="support-service-record-view" title="Service execution evidence" description="Check the parent request, assigned employee, lifecycle, work notes and consumed parts." path="Service Records → View" />

A task can progress from **Open** to **In Progress**, then **Quality Assurance** and **Closed** as permitted. QA can return to In Progress for rework. Some permitted stages can be skipped, but never label untested work as successfully quality-checked.

Record performed work, test outcome, technician, times and parts actually consumed. Inventory owns the stock movements for consumed or reversed parts; do not confuse a reserved part with a consumed part. Labour entries and third-party costs support job costing but do not independently invoice customers.

## Edit and finish

<IerpScreenshot id="support-service-record-edit" title="Edit an eligible service task" description="Update permitted task information, then use explicit stage actions for execution, QA and completion." path="Service Records → Edit" />

On a failed QA test, record the defect and reopen the task for rework. Verify retest results and customer notification. Before task closure, review unresolved parts, labour charges, evidence and the parent request's coverage and approval status.

**Financial boundary:** a completed service task does not automatically close the parent ticket, authorize uncovered customer work, issue a Sales invoice or collect money. Confirm those decisions on the maintenance request and in the financial owner modules.

Related: [Maintenance Requests](/screens/support/maintenance-request), [Tickets](/screens/support/ticket), [Maintenance Schedules](/screens/support/maintenance-schedule).
