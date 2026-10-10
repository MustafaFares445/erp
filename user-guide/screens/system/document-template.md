---
title: "Document Templates"
module: system
pages: ["index", "edit"]
screenshots: ["system-document-template-index", "system-document-template-edit"]
lastVerified: 2026-10-07
---

# Document Templates

<IerpNavigationPath :items='["Administration", "Templates", "Document Templates"]' />

<IerpPageSummary purpose="Maintain reusable business-document templates used when the ERP renders supported printable/exportable documents." useWhen="You need to work with document templates records or follow their lifecycle." owner="Administration" />

## Overview

Maintain reusable business-document templates used when the ERP renders supported printable/exportable documents.

System contains access, templates and shared configuration surfaces used across the ERP.

## How to open it

Use the dashboard module switcher and follow **Administration → Templates → Document Templates**. The exact visible options depend on your role.

## Screens in this area

| Screen | What you use it for |
| --- | --- |
| List page | The main Document Templates list. Use it to search, filter, sort, open records and run the actions available to your role. |
| Edit page | The Document Templates editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary. |

## When to use this page

Use **Document Templates** when your work belongs to this Administration responsibility. The page keeps the record in the module that owns the business fact instead of duplicating it in another area.

## Typical tasks

- Find an existing record using search, views and filters, then open it from the working list.
- Edit fields only while the lifecycle and your permission still allow direct editing.

## List page

<IerpScreenshot id="system-document-template-index" title="Document Templates — List page" description="The main Document Templates list. Use it to search, filter, sort, open records and run the actions available to your role." path="Administration → Templates → Document Templates" imageSrc="/images/en/system/system-document-template-index.png" />

The main Document Templates list. Use it to search, filter, sort, open records and run the actions available to your role.

The screen is a working queue: search and filters narrow records, saved or preset views focus common tasks, and row actions open the record without changing its lifecycle by themselves.

## Edit page

<IerpScreenshot id="system-document-template-edit" title="Document Templates — Edit page" description="The Document Templates editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary." path="Administration → Templates → Document Templates → Edit page" imageSrc="/images/en/system/system-document-template-edit.png" />

The Document Templates editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary.

### Form fields and sections

This screen has no conventional editable form. Work through the actions and read-only information exposed by the page.

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
