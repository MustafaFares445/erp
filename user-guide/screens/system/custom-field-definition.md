---
title: "Custom Fields"
module: system
pages: ["index"]
screenshots: ["system-custom-field-definition-index"]
lastVerified: 2026-10-07
---

# Custom Fields

<IerpNavigationPath :items='["Administration", "Configurations", "Custom Fields"]' />

<IerpPageSummary purpose="Define governed custom fields that extend supported records without adding uncontrolled free-form database structure." useWhen="You need to work with custom fields records or follow their lifecycle." owner="Administration" />

## Overview

Define governed custom fields that extend supported records without adding uncontrolled free-form database structure.

System contains access, templates and shared configuration surfaces used across the ERP.

## How to open it

Use the dashboard module switcher and follow **Administration → Configurations → Custom Fields**. The exact visible options depend on your role.

## Screens in this area

| Screen | What you use it for |
| --- | --- |
| List page | The main Custom Fields list. Use it to search, filter, sort, open records and run the actions available to your role. |

## When to use this page

Use **Custom Fields** when your work belongs to this Administration responsibility. The page keeps the record in the module that owns the business fact instead of duplicating it in another area.

## Typical tasks

- Find an existing record using search, views and filters, then open it from the working list.

## List page

<IerpScreenshot id="system-custom-field-definition-index" title="Custom Fields — List page" description="The main Custom Fields list. Use it to search, filter, sort, open records and run the actions available to your role." path="Administration → Configurations → Custom Fields" imageSrc="/images/en/system/system-custom-field-definition-index.png" />

The main Custom Fields list. Use it to search, filter, sort, open records and run the actions available to your role.

The screen is a working queue: search and filters narrow records, saved or preset views focus common tasks, and row actions open the record without changing its lifecycle by themselves.

### Main table information

The list can expose the following record information, depending on permissions and table configuration:

- **Entity Type**
- **Name**
- **Code**
- **Data Type**
- **Is Required**
- **Is Active**
- **Values Count**
- **Sort Order**

### Filters

Use filters to narrow the working set without changing records:

- **Entity Type** (SelectFilter)
- **Data Type** (SelectFilter)
- **Is Active** (TernaryFilter)

## Fields and form behavior

### Identity & master data

| Field / area | Control | How to use it |
| --- | --- | --- |
| Entity Type | Select | Selects the entity type from the configured choices that are valid for this record and its current context. |
| Name | TextInput | Stores the human-readable name shown in lists, selectors and reports. |
| Code | TextInput | Stores the stable business identifier used to search, reference and distinguish this record. |
| Data Type | Select | Selects the data type from the configured choices that are valid for this record and its current context. |

### Additional information

| Field / area | Control | How to use it |
| --- | --- | --- |
| Options | TagsInput | Stores the options as structured data on this record so it can be reused by the owning workflow and related reports. |
| Description | Textarea | Stores the business description shown with the record or line. Use it for meaningful transaction context, not to replace structured fields. |
| Sort Order | TextInput | Controls ordering/priority when multiple configured rules or entries can apply. |
| Is Required | Toggle | Enables or disables the is required behavior for future processing of this record. |
| Is Active | Toggle | Enables or disables the is active behavior for future processing of this record. |

Required, visible and editable behavior can be conditional. A field may appear only after another selection or only while the record remains in an editable state.

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
