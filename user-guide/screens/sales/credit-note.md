---
title: "Credit Notes"
module: sales
pages: ["index", "create", "view", "edit"]
screenshots: ["sales-credit-note-index", "sales-credit-note-create", "sales-credit-note-view", "sales-credit-note-edit"]
lastVerified: 2026-10-07
---

# Credit Notes

<IerpNavigationPath :items='["Sales", "Billing & Payments", "Credit Notes"]' />

<IerpPageSummary purpose="Create controlled customer credits against invoiced value and preserve the reason, source invoice and any return provenance." useWhen="You need to work with credit notes records or follow their lifecycle." owner="Sales" />

## Overview

Create controlled customer credits against invoiced value and preserve the reason, source invoice and any return provenance.

Sales controls the customer commercial lifecycle from quotation and confirmed demand through fulfillment, billing, collection and credit correction.

## How to open it

Use the dashboard module switcher and follow **Sales → Billing & Payments → Credit Notes**. The exact visible options depend on your role.

## Screens in this area

| Screen | What you use it for |
| --- | --- |
| List page | The main Credit Notes list. Use it to search, filter, sort, open records and run the actions available to your role. |
| Create page | The Credit Notes creation form. Complete the required business information and review dependent fields before saving or submitting. |
| Record details | The Credit Notes detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions. |
| Edit page | The Credit Notes editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary. |

## When to use this page

Use **Credit Notes** when your work belongs to this Sales responsibility. The page keeps the record in the module that owns the business fact instead of duplicating it in another area.

## Typical tasks

- Find an existing record using search, views and filters, then open it from the working list.
- Create a new record from the page action and complete the required business sections before saving.
- Review the record's current status, source relationships, totals/evidence and related work from the detail page.
- Edit fields only while the lifecycle and your permission still allow direct editing.
- Move the record through its workflow using actions such as **Confirm**, **Reverse**, **Generate PDF** rather than changing status fields manually.

## Verified operating behavior

- A Credit Note corrects or reduces invoiced customer value; it is not itself the physical return operation.
- Choose the source invoice and a reason category, then record a human-readable reason. Totals are derived from the credit-note lines/context rather than freely edited totals.
- Stock Consequence makes the relationship to physical goods explicit. When Goods Returned is selected, a Posted Inventory Return for the same customer becomes required.
- Confirm is the financial commitment/posting path for the credit. Reverse is an explicit reversal; do not delete or rewrite a confirmed credit note to undo history.
- Generate PDF is available only after confirmation.

## List page

<IerpScreenshot id="sales-credit-note-index" title="Credit Notes — List page" description="The main Credit Notes list. Use it to search, filter, sort, open records and run the actions available to your role." path="Sales → Billing & Payments → Credit Notes" imageSrc="/images/en/sales/sales-credit-note-index.png" />

The main Credit Notes list. Use it to search, filter, sort, open records and run the actions available to your role.

### Credit-note working views

Use **Draft**, **Confirmed this month**, **Confirmed**, **Sales returns**, and **Reversed / Cancelled** to separate preparation, posted credit work, return-related credits and historical reversals. The list keeps the source invoice and Inventory consequence visible so a financial correction can be traced to both its billing source and any physical-return context.

**Export CSV** downloads the current filtered/searched credit-note rows. Confirmation, PDF generation and reversal stay on the record detail because they change or document the credit-note lifecycle.

The screen is a working queue: search and filters narrow records, saved or preset views focus common tasks, and row actions open the record without changing its lifecycle by themselves.

### Main table information

The list can expose the following record information, depending on permissions and table configuration:

- **Credit Note Number**
- **Customer Company Name**
- **Invoice Invoice Number**
- **Reason Category**
- **Grand Total**
- **Stock Consequence**
- **Status**
- **Issue Date**

## Create page

<IerpScreenshot id="sales-credit-note-create" title="Credit Notes — Create page" description="The Credit Notes creation form. Complete the required business information and review dependent fields before saving or submitting." path="Sales → Billing & Payments → Credit Notes → Create page" imageSrc="/images/en/sales/sales-credit-note-create.png" />

The Credit Notes creation form. Complete the required business information and review dependent fields before saving or submitting.

### Choose the inventory consequence explicitly

Select the customer/source invoice and a reason category, then choose one inventory consequence:

- **Goods returned** — a Posted Inventory Return for the same customer becomes required.
- **Customer retained goods** — the financial credit does not claim that goods returned to stock.
- **No inventory movement** — use when the correction has no physical stock consequence.

Subtotal, tax and grand total shown by this form are calculated/read-only context rather than free-form correction amounts. The credit-note lines and service rules determine the controlled credit value.

### Before you create the record

- Confirm the master records referenced by the form are active and correct.
- Review the source document or prior workflow step when this record is created from another process.
- Confirm your role has permission for the later submit/approve/post actions you expect to use.

### Form fields and sections

### Identity & master data

| Field / area | Control | How to use it |
| --- | --- | --- |
| Customer | Select | Selects the customer that owns the commercial, service or financial record. Customer defaults can influence currency, price list, payment terms and assigned sales context. |

### Commercial terms & value

| Field / area | Control | How to use it |
| --- | --- | --- |
| Subtotal | TextInput | Stores or displays the monetary subtotal used by totals, posting or performance calculations according to the owning workflow. |
| Tax Total | TextInput | Stores or displays the monetary tax total used by totals, posting or performance calculations according to the owning workflow. |
| Grand Total | TextInput | Stores or displays the monetary grand total used by totals, posting or performance calculations according to the owning workflow. |

### Product, quantity & inventory context

| Field / area | Control | How to use it |
| --- | --- | --- |
| Stock Consequence | Radio | Selects the stock consequence from the configured choices that are valid for this record and its current context. |

### Dates & scheduling

| Field / area | Control | How to use it |
| --- | --- | --- |
| Issue Date | DatePicker | Records the commercial issue date from which the quotation/invoice document is considered issued for its lifecycle. |

### Workflow, approval & evidence

| Field / area | Control | How to use it |
| --- | --- | --- |
| Credit Note Number | TextInput | Stores the stable business identifier used to search, reference and distinguish this record. |
| Reason Category | Select | Selects the reason category from the configured choices that are valid for this record and its current context. |
| Reason | Textarea | Records the business reason for an exception, correction, cancellation or override so reviewers can understand and audit why it occurred. |

### Additional information

| Field / area | Control | How to use it |
| --- | --- | --- |
| Invoice | Select | Selects the invoice from the configured choices that are valid for this record and its current context. |
| Inventory Return | Select | Selects the inventory return from the configured choices that are valid for this record and its current context. |

### Before saving

Verify required identities, dates, currency/UOM values, line-level quantities and any evidence requested by the workflow. Dependent selectors can change after choosing a customer, supplier, product or warehouse.

## Record details

<IerpScreenshot id="sales-credit-note-view" title="Credit Notes — Record details" description="The Credit Notes detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions." path="Sales → Billing & Payments → Credit Notes → Record details" imageSrc="/images/en/sales/sales-credit-note-view.png" />

The Credit Notes detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions.

### Confirm, refund and reverse

A Draft can be edited until **Confirm** applies the controlled credit effect. The detail page preserves source invoice, reason, stock consequence, line values, linked Inventory Return where relevant, refunds and journal evidence.

**Generate PDF** is available for confirmed notes. **Reverse** reverses a confirmed credit note through the service workflow; it does not delete the original financial history.

### Information shown on the detail screen

The detail/infolist implementation can expose:

- **Credit Note Number**
- **Customer Company Name**
- **Source Invoice**
- **Status**
- **Grand Total**
- **Reason Category**
- **Lines**
- **Description**
- **Quantity**
- **Unit Price**
- **Tax Amount**
- **Line Total**
- **Source Reference**
- **Stock Consequence**
- **Inventory Return Number**
- **Inventory Return Status**
- **Invoice Invoice Number**
- **Invoice Total**
- **Previously Credited**
- **This Credit**
- **Credited After Confirmation**
- **Remaining Creditable**
- **Current Outstanding**
- **Subtotal**
- **Tax Total**
- **Issue Date**
- **Confirmed At**
- **Reversed At**
- **Reason**
- **Refunds**
- **Refund Number**
- **Refund Date**
- **Amount**
- **Paid At**
- **Credit Note PDF**
- **Journal Entries**
- **Entry Number**
- **Entry Date**
- **Chart Account Name**
- **Chart Account Code**

## Edit page

<IerpScreenshot id="sales-credit-note-edit" title="Credit Notes — Edit page" description="The Credit Notes editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary." path="Sales → Billing & Payments → Credit Notes → Edit page" imageSrc="/images/en/sales/sales-credit-note-edit.png" />

The Credit Notes editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary.

### Form fields and sections

### Identity & master data

| Field / area | Control | How to use it |
| --- | --- | --- |
| Customer | Select | Selects the customer that owns the commercial, service or financial record. Customer defaults can influence currency, price list, payment terms and assigned sales context. |

### Commercial terms & value

| Field / area | Control | How to use it |
| --- | --- | --- |
| Subtotal | TextInput | Stores or displays the monetary subtotal used by totals, posting or performance calculations according to the owning workflow. |
| Tax Total | TextInput | Stores or displays the monetary tax total used by totals, posting or performance calculations according to the owning workflow. |
| Grand Total | TextInput | Stores or displays the monetary grand total used by totals, posting or performance calculations according to the owning workflow. |

### Product, quantity & inventory context

| Field / area | Control | How to use it |
| --- | --- | --- |
| Stock Consequence | Radio | Selects the stock consequence from the configured choices that are valid for this record and its current context. |

### Dates & scheduling

| Field / area | Control | How to use it |
| --- | --- | --- |
| Issue Date | DatePicker | Records the commercial issue date from which the quotation/invoice document is considered issued for its lifecycle. |

### Workflow, approval & evidence

| Field / area | Control | How to use it |
| --- | --- | --- |
| Credit Note Number | TextInput | Stores the stable business identifier used to search, reference and distinguish this record. |
| Reason Category | Select | Selects the reason category from the configured choices that are valid for this record and its current context. |
| Reason | Textarea | Records the business reason for an exception, correction, cancellation or override so reviewers can understand and audit why it occurred. |

### Additional information

| Field / area | Control | How to use it |
| --- | --- | --- |
| Invoice | Select | Selects the invoice from the configured choices that are valid for this record and its current context. |
| Inventory Return | Select | Selects the inventory return from the configured choices that are valid for this record and its current context. |

### Editing rules

Editing is not a replacement for a workflow transition. Once a transaction is approved, posted, sent or otherwise locked, IERP can hide or reject edits and require a dedicated correction or reversal path.

## Actions and lifecycle controls

The implementation currently exposes action definitions including:
- **Confirm** — confirms the business commitment represented by the record and can unlock downstream work.
- **Reverse** — creates or applies an explicit reversal so posted history remains auditable.
- **Generate PDF** — queues generation or regeneration of the printable business document; the action does not by itself imply an immediate browser preview/download.

High-impact actions can change stock, money, approvals or lifecycle state. Read the confirmation dialog before continuing.

## Statuses used by this record

The current status vocabulary is:

<IerpStatus tone="neutral">Draft</IerpStatus> <IerpStatus tone="neutral">Confirmed</IerpStatus> <IerpStatus tone="neutral">Reversed</IerpStatus> <IerpStatus tone="danger">Cancelled</IerpStatus>

Use the action buttons exposed by the record to move between statuses. The presence of a status does not mean every user may perform the transition.

## Related tabs and linked records

The record can include related information or work areas such as:

- Credit Note Lines

Related tabs provide context, not duplicate master records. Open the owning module when you need to perform a dedicated downstream workflow.

## Business effect

Sales may create or update downstream fulfillment, invoice, payment and accounting work. Stock changes only through Inventory actions.

## Common reasons an action is unavailable

- Your role does not have the required permission.
- The record is not in the status required by that action.
- A prerequisite approval, source document or configuration is missing.
- A posted or confirmed transaction is intentionally immutable and needs a correction or reversal workflow.
- A dependent module still has unfinished work, such as receiving, payment, maintenance or fulfillment.

## Related workflows

- [Quote To Cash](/workflows/quote-to-cash)
- [Customer Return To Refund](/workflows/customer-return-to-refund)
- [Sales Shortage Procurement](/workflows/sales-shortage-procurement)
