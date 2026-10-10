---
title: "Support Queues"
module: support
pages: ["index", "create", "edit"]
screenshots: ["support-support-queue-index", "support-support-queue-create", "support-support-queue-edit"]
lastVerified: 2026-10-07
---

# Support queues: visible triage worklists

<IerpPageSummary purpose="Group cases into operational worklists by status, priority, service path, SLA risk and team, without changing ticket state merely by filtering." useWhen="Support Managers need a targeted queue for daily assignment or customer follow-up." owner="Support and Maintenance" />

## When to use this page

Support Managers need a targeted queue for daily assignment or customer follow-up.

## Typical tasks

- Follow the documented “Create a useful queue” instructions below.
- Follow the documented “Edit without losing assignments” instructions below.

## Availability and scope

Queues belong to the optional Smart Routing workspace and require both an enabled rollout and Support Queue permissions. Queue membership is derived from criteria: it is not the same as assigning a ticket to a team or technician.

<IerpScreenshot id="support-support-queue-index" title="Support work queues" description="Review names, team context and queue activation before selecting a worklist." path="Advanced Support → Support Queues" />

## Create a useful queue

<IerpScreenshot id="support-support-queue-create" title="Create support queue" description="Define status, priority, path, SLA risk and age filters with an optional owning team." path="Support Queues → Create" />

Choose a clear name and sort order; associate an active team if appropriate. Limit the filter to meaningful states, priorities and service paths. Additional criteria include **Only unassigned**, **Only SLA risk**, waiting-customer age and overall ticket age. Check the logic with realistic triaged cases before using it operationally.

## Edit without losing assignments

<IerpScreenshot id="support-support-queue-edit" title="Edit queue criteria" description="Review how revised filters change the visible working set, not the underlying ticket history." path="Support Queues → Edit" />

Editing the queue can add or remove tickets from its visible working set but should not change their owners, SLA clocks or billing states. Use the ticket workspace for actual assignment, routing, repair and closure. Inactive queues should not be presented as current workload instructions.

Related: [Tickets](/screens/support/ticket), [Teams](/screens/support/support-team), [Routing Rules](/screens/support/support-routing-rule).
