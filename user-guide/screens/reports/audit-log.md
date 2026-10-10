---
title: "Audit Log"
module: reports
pages: ["index", "view"]
screenshots: ["reports-audit-log-index", "reports-audit-log-view"]
lastVerified: 2026-10-07
---

# Audit Log

<IerpNavigationPath :items='["Reports", "Audit", "Audit Log"]' />

<IerpPageSummary purpose="Review audited system/business changes, actor and timing context without using the audit log as a write path." useWhen="You need to work with audit log records or follow their lifecycle." owner="Reports" />

## Overview

Review audited system/business changes, actor and timing context without using the audit log as a write path.

Reports provides consolidated operational and audit views. Reports explain business facts; corrections must be made in the module that owns the source record.

## How to open it

Use the dashboard module switcher and follow **Reports → Audit → Audit Log**. The exact visible options depend on your role.

## Screens in this area

| Screen | What you use it for |
| --- | --- |
| List page | The main Audit Log list. Use it to search, filter, sort, open records and run the actions available to your role. |
| Record details | The Audit Log detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions. |

## When to use this page

Use **Audit Log** when your work belongs to this Reports responsibility. The page keeps the record in the module that owns the business fact instead of duplicating it in another area.

## Typical tasks

- Find an existing record using search, views and filters, then open it from the working list.
- Review the record's current status, source relationships, totals/evidence and related work from the detail page.

## List page

<IerpScreenshot id="reports-audit-log-index" title="Audit Log — List page" description="The main Audit Log list. Use it to search, filter, sort, open records and run the actions available to your role." path="Reports → Audit → Audit Log" imageSrc="/images/en/reports/reports-audit-log-index.png" />

The main Audit Log list. Use it to search, filter, sort, open records and run the actions available to your role.

The screen is a working queue: search and filters narrow records, saved or preset views focus common tasks, and row actions open the record without changing its lifecycle by themselves.

### Main table information

The list can expose the following record information, depending on permissions and table configuration:

- **Created At**
- **Description**
- **Causer Name**
- **Source Channel**

### Filters

Use filters to narrow the working set without changing records:

- **Causer** (SelectFilter)
- **Description** (SelectFilter)
- **Created At** (Filter)

## Record details

<IerpScreenshot id="reports-audit-log-view" title="Audit Log — Record details" description="The Audit Log detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions." path="Reports → Audit → Audit Log → Record details" imageSrc="/images/en/reports/reports-audit-log-view.png" />

The Audit Log detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions.

### Information shown on the detail screen

The detail/infolist implementation can expose:

- **Description**
- **Causer Name**
- **Source Channel**
- **Ip Address**
- **Created At**
- **Old Values**
- **New Values**

## Actions and lifecycle controls

Available actions are provided by the page and record state. If an expected action is missing, check the status and your permissions.

## Business effect

Reports do not mutate source records. Resolve discrepancies in the owning module.

## Common reasons an action is unavailable

- Your role does not have the required permission.
- The record is not in the status required by that action.
- A prerequisite approval, source document or configuration is missing.
- A posted or confirmed transaction is intentionally immutable and needs a correction or reversal workflow.
- A dependent module still has unfinished work, such as receiving, payment, maintenance or fulfillment.

## Related workflows

- [Core business workflows](/workflows/)
