---
title: "Advanced Support"
module: support
pages: ["index", "create", "edit"]
screenshots: ["support-support-team-index", "support-support-team-create", "support-support-team-edit"]
lastVerified: 2026-10-07
---

# Support teams: ownership and assignment capacity

<IerpPageSummary purpose="Maintain active support teams, their managers, assignment strategy and optional capacity for ticket routing." useWhen="Support Managers configure who owns a category of customer issues." owner="Support and Maintenance" />

## When to use this page

Support Managers configure who owns a category of customer issues.

## Typical tasks

- Follow the documented “Create and verify” instructions below.
- Follow the documented “Edit ownership safely” instructions below.

## Availability

Support Teams are part of optional **Smart Routing** and require the feature to be enabled plus role authorization. Creating a team is configuration work, not evidence of any ticket assignment.

<IerpScreenshot id="support-support-team-index" title="Support team directory" description="Find the responsible team, its current assignment strategy and activation status." path="Advanced Support → Teams" />

## Create and verify

<IerpScreenshot id="support-support-team-create" title="New support team" description="Supply an immutable-looking code, clear name, assignment strategy, capacity and optional manager." path="Teams → Create" />

Give each team a meaningful unique code and name. Choose the assignment strategy deliberately; **Manual** does not auto-assign. Set a sensible capacity if applicable, identify a real manager and confirm the team is active before using it in a routing rule.

## Edit ownership safely

<IerpScreenshot id="support-support-team-edit" title="Edit support team" description="Check how assignment settings affect new tickets without silently changing past case history." path="Teams → Edit" />

Changing or disabling a team can remove it from the active selection options and influence future routing, but does not by itself transfer all existing tickets or close ongoing cases. Review pending assignments, team membership and required technician skills before changing production ownership.

Related: [Skills](/screens/support/support-skill), [Routing Rules](/screens/support/support-routing-rule), [Queues](/screens/support/support-queue), [Tickets](/screens/support/ticket).
