---
title: "Invoices"
module: sales
pages: ["index", "create", "view", "edit"]
screenshots: ["sales-invoice-index", "sales-invoice-create", "sales-invoice-view", "sales-invoice-edit"]
lastVerified: 2026-10-07
---

# Invoices

<IerpNavigationPath :items='["Sales", "Billing & Payments", "Invoices"]' />

<IerpPageSummary purpose="Create, issue and review customer invoices while keeping document lifecycle, receivable balance and payment settlement as separate controlled facts." useWhen="You need to work with invoices records or follow their lifecycle." owner="Sales" />

## Overview

Create, issue and review customer invoices while keeping document lifecycle, receivable balance and payment settlement as separate controlled facts.

Sales controls the customer commercial lifecycle from quotation and confirmed demand through fulfillment, billing, collection and credit correction.

## How to open it

Use the dashboard module switcher and follow **Sales → Billing & Payments → Invoices**. The exact visible options depend on your role.

## Screens in this area

| Screen | What you use it for |
| --- | --- |
| List page | The main Invoices list. Use it to search, filter, sort, open records and run the actions available to your role. |
| Create page | The Invoices creation form. Complete the required business information and review dependent fields before saving or submitting. |
| Record details | The Invoices detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions. |
| Edit page | The Invoices editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary. |

## When to use this page

- Use Invoices to record customer billing after eligible delivery work or for an explicitly supported standalone billing case.
- Invoice document state and financial settlement state are deliberately separate.

## Typical tasks

- Use the list views to find invoices that need issue, collection or exception follow-up.
- Open invoices created from eligible delivery/billing flows; the invoice list intentionally does not expose a generic New Invoice header action.
- Issue Draft invoices, record payment for issued outstanding invoices, and use explicit Credit Note / Write-off paths for corrections.
- Generate/send invoice documents separately from the accounting issue event.

## Verified operating behavior

- Use a Draft invoice to review commercial values before posting. Issue is the posting boundary: it freezes commercial content and posts Accounts Receivable, Revenue and Deferred Sales Tax.
- Sending an email copy is not the accounting event. It is available only when a stored invoice PDF exists and does not change the ledger.
- Generate PDF is available for issued invoices and queues/regenerates the stored invoice document.
- Confirm Receipt records how the customer confirmed receipt and can include notes and optional signature evidence. It is available after the invoice is Sent.
- Record Payment opens the Payment creation flow only for issued invoices with an outstanding balance.
- Write Off Receivable is for a valid amount the company intentionally stops collecting; Create Credit Note is for correcting/reducing the invoiced amount itself. They are distinct financial corrections.
- If automatic customer-deposit application failed during issue, Retry Deposit Application safely re-checks unallocated posted deposits and is designed to be idempotent.

## List page

<IerpScreenshot id="sales-invoice-index" title="Invoices — List page" description="The main Invoices list. Use it to search, filter, sort, open records and run the actions available to your role." path="Sales → Billing & Payments → Invoices" imageSrc="/images/en/sales/sales-invoice-index.png" />

The main Invoices list. Use it to search, filter, sort, open records and run the actions available to your role.

### Document status vs financial status

Do not treat **Document status** and **Financial status** as the same thing. Document status describes the invoice lifecycle; Financial status is derived from payment, credit and write-off settlement. **Outstanding** shows the amount still open after those effects.

The **Next action** column and Needs attention/Overdue/Unpaid/Partially paid views are designed as collection queues. Export CSV downloads the current filtered/searched invoice result.

The screen is a working queue: search and filters narrow records, saved or preset views focus common tasks, and row actions open the record without changing its lifecycle by themselves.

### Main table information

The list can expose the following record information, depending on permissions and table configuration:

- **Invoice Number**
- **Customer Company Name**
- **Status**
- **Financial Status**
- **Total Amount**
- **Outstanding**
- **Due Date**
- **Next Action**
- **Invoice Date**
- **Amount Paid**
- **Created By Name**

### Global search

This resource participates in dashboard search through:

- Invoice Number
- Customer Company Name
- Customer Customer Code
- Order Order Number

## Create page

<IerpScreenshot id="sales-invoice-create" title="Invoices — Create page" description="The Invoices creation form. Complete the required business information and review dependent fields before saving or submitting." path="Sales → Billing & Payments → Invoices → Create page" imageSrc="/images/en/sales/sales-invoice-create.png" />

The Invoices creation form. Complete the required business information and review dependent fields before saving or submitting.

### Before you create the record

- Verify the customer, source delivery/order where applicable, currency, lines, tax and due-date/payment terms.
- Do not issue an invoice to work around unfinished fulfillment when the workflow requires a completed delivery source.

### Form fields and sections

### Identity & master data

| Field / area | Control | How to use it |
| --- | --- | --- |
| Invoice Number | TextInput | Stores the stable business identifier used to search, reference and distinguish this record. |
| Customer | Select | Selects the customer that owns the commercial, service or financial record. Customer defaults can influence currency, price list, payment terms and assigned sales context. |

### Commercial terms & value

| Field / area | Control | How to use it |
| --- | --- | --- |
| Payment Term | Select | Applies the agreed payment term that controls expected due-date behavior for billing or supplier liability. |
| Subtotal | TextInput | Stores or displays the monetary subtotal used by totals, posting or performance calculations according to the owning workflow. |
| Tax Total | TextInput | Stores or displays the monetary tax total used by totals, posting or performance calculations according to the owning workflow. |
| Total Amount | TextInput | Stores or displays the monetary total amount used by totals, posting or performance calculations according to the owning workflow. |

### Dates & scheduling

| Field / area | Control | How to use it |
| --- | --- | --- |
| Invoice Date | DatePicker | Records the invoice date used by the workflow for scheduling, validity, due-date logic or historical context. |
| Due Date | DatePicker | Records when the financial amount becomes due according to the document and payment terms. |

### Additional information

| Field / area | Control | How to use it |
| --- | --- | --- |
| Description | Textarea | Stores the business description shown with the record or line. Use it for meaningful transaction context, not to replace structured fields. |

### Before saving

Verify required identities, dates, currency/UOM values, line-level quantities and any evidence requested by the workflow. Dependent selectors can change after choosing a customer, supplier, product or warehouse.

## Record details

<IerpScreenshot id="sales-invoice-view" title="Invoices — Record details" description="The Invoices detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions." path="Sales → Billing & Payments → Invoices → Record details" imageSrc="/images/en/sales/sales-invoice-view.png" />

The Invoices detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions.

### Issue, collect and correct

Issuing is the accounting boundary: it freezes commercial content and posts Accounts Receivable, Revenue and Deferred Sales Tax. After issue, use **Record payment** for collection, **Create credit note** when invoiced value itself must be reduced, or **Write off receivable** when the company intentionally stops collecting valid debt.

Document generation and delivery are separate: **Generate PDF** queues the PDF; **Send email copy** is available only after the stored PDF exists and does not post accounting. Receipt confirmation records evidence that the customer received the document.

### Information shown on the detail screen

The detail/infolist implementation can expose:

- **Invoice Number**
- **Customer Company Name**
- **Status**
- **Total Amount**
- **Outstanding Amount**
- **Lines**
- **Product Variant SKU**
- **Description**
- **Quantity**
- **Unit Price**
- **Tax Amount**
- **Line Total**
- **Resolved Price Source**
- **Resolved Price Tier Name**
- **List Price Minor**
- **Floor Price Minor**
- **Pricing Status**
- **Payment Allocations**
- **Payment Payment Number**
- **Payment Payment Date**
- **Amount**
- **Deposit Note**
- **Total Applied**
- **Delivery Links**
- **Inventory Operation Operation Number**
- **Inventory Operation Completed At**
- **Inventory Operation Customer Company Name**
- **Deposit Application Issues**
- **Occurred At**
- **Error Message**
- **Order Order Number**
- **Order Quotation Quotation Number**
- **Invoice Date**
- **Due Date**
- **Payment Term Name**
- **Subtotal**
- **Tax Total**
- **Amount Paid**
- **Credited Amount**
- **Written Off**

## Edit page

<IerpScreenshot id="sales-invoice-edit" title="Invoices — Edit page" description="The Invoices editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary." path="Sales → Billing & Payments → Invoices → Edit page" imageSrc="/images/en/sales/sales-invoice-edit.png" />

The Invoices editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary.

### Form fields and sections

### Identity & master data

| Field / area | Control | How to use it |
| --- | --- | --- |
| Invoice Number | TextInput | Stores the stable business identifier used to search, reference and distinguish this record. |
| Customer | Select | Selects the customer that owns the commercial, service or financial record. Customer defaults can influence currency, price list, payment terms and assigned sales context. |

### Commercial terms & value

| Field / area | Control | How to use it |
| --- | --- | --- |
| Payment Term | Select | Applies the agreed payment term that controls expected due-date behavior for billing or supplier liability. |
| Subtotal | TextInput | Stores or displays the monetary subtotal used by totals, posting or performance calculations according to the owning workflow. |
| Tax Total | TextInput | Stores or displays the monetary tax total used by totals, posting or performance calculations according to the owning workflow. |
| Total Amount | TextInput | Stores or displays the monetary total amount used by totals, posting or performance calculations according to the owning workflow. |

### Dates & scheduling

| Field / area | Control | How to use it |
| --- | --- | --- |
| Invoice Date | DatePicker | Records the invoice date used by the workflow for scheduling, validity, due-date logic or historical context. |
| Due Date | DatePicker | Records when the financial amount becomes due according to the document and payment terms. |

### Additional information

| Field / area | Control | How to use it |
| --- | --- | --- |
| Description | Textarea | Stores the business description shown with the record or line. Use it for meaningful transaction context, not to replace structured fields. |

### Editing rules

Editing is not a replacement for a workflow transition. Once a transaction is approved, posted, sent or otherwise locked, IERP can hide or reject edits and require a dedicated correction or reversal path.

## What happens after this step

- Issue posts the approved invoice accounting effect through Accounting.
- Payments and allocations update financial settlement independently from the invoice's commercial document lifecycle.
- Corrections use Credit Notes/Refunds or other explicit financial corrections rather than rewriting issued history.

## Actions and lifecycle controls

The implementation currently exposes action definitions including:
- **Issue** — executes this named workflow action after validating the current status, permission and prerequisites.
- **Retry Deposit Application** — retries the previously failed transition using the current validated source data.
- **Generate PDF** — queues generation or regeneration of the printable business document; the action does not by itself imply an immediate browser preview/download.
- **Send Invoice** — executes this named workflow action after validating the current status, permission and prerequisites.
- **Confirm Receipt** — executes this named workflow action after validating the current status, permission and prerequisites.
- **Record Payment** — continues the controlled payment flow and records the financial action only when its prerequisites are satisfied.
- **Write Off** — executes this named workflow action after validating the current status, permission and prerequisites.
- **Create Credit Note** — creates the linked credit note through the supported workflow and preserves this record as its source.

High-impact actions can change stock, money, approvals or lifecycle state. Read the confirmation dialog before continuing.

## Statuses used by this record

The current status vocabulary is:

<IerpStatus tone="neutral">Draft</IerpStatus> <IerpStatus tone="neutral">Issued</IerpStatus> <IerpStatus tone="primary">Sent</IerpStatus> <IerpStatus tone="danger">Written Off</IerpStatus> <IerpStatus tone="danger">Cancelled</IerpStatus>

Use the action buttons exposed by the record to move between statuses. The presence of a status does not mean every user may perform the transition.

## Related tabs and linked records

The record can include related information or work areas such as:

- Collaboration Entries

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
