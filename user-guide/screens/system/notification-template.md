---
title: "Notification templates"
module: system
pages: ["index", "edit"]
screenshots: ["system-notification-template-index", "system-notification-template-edit"]
lastVerified: 2026-10-07
---

# Notification templates

<IerpNavigationPath :items='["Administration", "Templates", "Notification templates"]' />

<IerpPageSummary purpose="Maintain reusable notification content/templates used by supported business events and delivery channels." useWhen="You need to work with notification templates records or follow their lifecycle." owner="Administration" />

## Overview

Maintain reusable notification content/templates used by supported business events and delivery channels.

System contains access, templates and shared configuration surfaces used across the ERP.

## How to open it

Use the dashboard module switcher and follow **Administration → Templates → Notification templates**. The exact visible options depend on your role.

## Screens in this area

| Screen | What you use it for |
| --- | --- |
| List page | The main Notification templates list. Use it to search, filter, sort, open records and run the actions available to your role. |
| Edit page | The Notification templates editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary. |

## When to use this page

Use **Notification templates** when your work belongs to this Administration responsibility. The page keeps the record in the module that owns the business fact instead of duplicating it in another area.

## Typical tasks

- Find an existing record using search, views and filters, then open it from the working list.
- Edit fields only while the lifecycle and your permission still allow direct editing.
- Move the record through its workflow using actions such as **Reset To Default** rather than changing status fields manually.

## List page

<IerpScreenshot id="system-notification-template-index" title="Notification templates — List page" description="The main Notification templates list. Use it to search, filter, sort, open records and run the actions available to your role." path="Administration → Templates → Notification templates" imageSrc="/images/en/system/system-notification-template-index.png" />

The main Notification templates list. Use it to search, filter, sort, open records and run the actions available to your role.

The screen is a working queue: search and filters narrow records, saved or preset views focus common tasks, and row actions open the record without changing its lifecycle by themselves.

### Main table information

The list can expose the following record information, depending on permissions and table configuration:

- **Notification**
- **When Sent**
- **Languages**
- **Status**
- **Last Updated**

## Edit page

<IerpScreenshot id="system-notification-template-edit" title="Notification templates — Edit page" description="The Notification templates editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary." path="Administration → Templates → Notification templates → Edit page" imageSrc="/images/en/system/system-notification-template-edit.png" />

The Notification templates editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary.

### Form fields and sections

### Additional information

| Field / area | Control | How to use it |
| --- | --- | --- |
| Is Active | Toggle | Enables or disables the is active behavior for future processing of this record. |

### Editing rules

Editing is not a replacement for a workflow transition. Once a transaction is approved, posted, sent or otherwise locked, IERP can hide or reject edits and require a dedicated correction or reversal path.

## Actions and lifecycle controls

The implementation currently exposes action definitions including:
- **Reset To Default** — executes this named workflow action after validating the current status, permission and prerequisites.

High-impact actions can change stock, money, approvals or lifecycle state. Read the confirmation dialog before continuing.

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
