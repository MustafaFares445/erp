---
title: "Dashboard User Roles"
module: system
pages: ["index", "edit"]
screenshots: ["system-dashboard-user-index", "system-dashboard-user-edit"]
lastVerified: 2026-10-07
---

# Dashboard User Roles

<IerpNavigationPath :items='["Administration", "Access", "Dashboard User Roles"]' />

<IerpPageSummary purpose="Manage administration users, roles and access context for the Filament dashboard without mixing access management with employee operational records." useWhen="You need to work with dashboard user roles records or follow their lifecycle." owner="Administration" />

## Overview

Manage administration users, roles and access context for the Filament dashboard without mixing access management with employee operational records.

System contains access, templates and shared configuration surfaces used across the ERP.

## How to open it

Use the dashboard module switcher and follow **Administration → Access → Dashboard User Roles**. The exact visible options depend on your role.

## Screens in this area

| Screen | What you use it for |
| --- | --- |
| List page | The main Dashboard User Roles list. Use it to search, filter, sort, open records and run the actions available to your role. |
| Edit page | The Dashboard User Roles editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary. |

## When to use this page

- Use Dashboard Users to manage who can access the administration interface and the role/permission context applied to that user.
- Permissions should be granted by business responsibility; cross-module permission boundaries are intentional.

## Typical tasks

- Find an existing record using search, views and filters, then open it from the working list.
- Edit fields only while the lifecycle and your permission still allow direct editing.

## List page

<IerpScreenshot id="system-dashboard-user-index" title="Dashboard User Roles — List page" description="The main Dashboard User Roles list. Use it to search, filter, sort, open records and run the actions available to your role." path="Administration → Access → Dashboard User Roles" imageSrc="/images/en/system/system-dashboard-user-index.png" />

The main Dashboard User Roles list. Use it to search, filter, sort, open records and run the actions available to your role.

The screen is a working queue: search and filters narrow records, saved or preset views focus common tasks, and row actions open the record without changing its lifecycle by themselves.

### Main table information

The list can expose the following record information, depending on permissions and table configuration:

- **Name**
- **Email**
- **Roles Name**

## Edit page

<IerpScreenshot id="system-dashboard-user-edit" title="Dashboard User Roles — Edit page" description="The Dashboard User Roles editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary." path="Administration → Access → Dashboard User Roles → Edit page" imageSrc="/images/en/system/system-dashboard-user-edit.png" />

The Dashboard User Roles editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary.

### Form fields and sections

### Identity & master data

| Field / area | Control | How to use it |
| --- | --- | --- |
| Name | TextInput | Stores the human-readable name shown in lists, selectors and reports. |
| Role Name | Select | Stores the human-readable role name shown in lists, selectors and reports. |

### Contacts & addresses

| Field / area | Control | How to use it |
| --- | --- | --- |
| Email | TextInput | Stores the email address used for the email contact or account context. |

### Editing rules

Editing is not a replacement for a workflow transition. Once a transaction is approved, posted, sent or otherwise locked, IERP can hide or reject edits and require a dedicated correction or reversal path.

## Actions and lifecycle controls

Available actions are provided by the page and record state. If an expected action is missing, check the status and your permissions.

## Business effect

System changes can affect future transactions, permissions and shared presentation. Posted history should remain stable.

## Common reasons an action is unavailable

- Your role does not have the required permission.
- The record is not in the status required by that action.
- A prerequisite approval, source document or configuration is missing.
- A posted or confirmed transaction is intentionally immutable and needs a correction or reversal workflow.
- A dependent module still has unfinished work, such as receiving, payment, maintenance or fulfillment.

## Related workflows

- [Core business workflows](/workflows/)
