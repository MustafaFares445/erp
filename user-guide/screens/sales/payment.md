---
title: "Payments"
module: sales
pages: ["index", "create", "view", "edit"]
screenshots: ["sales-payment-index", "sales-payment-create", "sales-payment-view", "sales-payment-edit"]
lastVerified: 2026-10-07
---

# Payments

<IerpNavigationPath :items='["Sales", "Billing & Payments", "Payments"]' />

<IerpPageSummary purpose="Record customer money, allocate it to eligible invoices or deposits, and post/reverse the controlled collection effect." useWhen="You need to work with payments records or follow their lifecycle." owner="Sales" />

## Overview

Record customer money, allocate it to eligible invoices or deposits, and post/reverse the controlled collection effect.

Sales controls the customer commercial lifecycle from quotation and confirmed demand through fulfillment, billing, collection and credit correction.

## How to open it

Use the dashboard module switcher and follow **Sales → Billing & Payments → Payments**. The exact visible options depend on your role.

## Screens in this area

| Screen | What you use it for |
| --- | --- |
| List page | The main Payments list. Use it to search, filter, sort, open records and run the actions available to your role. |
| Create page | The Payments creation form. Complete the required business information and review dependent fields before saving or submitting. |
| Record details | The Payments detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions. |
| Edit page | The Payments editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary. |

## When to use this page

- Use Payments to record customer money movement and allocate it to eligible issued invoices.
- Use unallocated posted money as customer-deposit context when the payment exceeds the immediate allocation.

## Typical tasks

- Find an existing record using search, views and filters, then open it from the working list.
- Create a new record from the page action and complete the required business sections before saving.
- Review the record's current status, source relationships, totals/evidence and related work from the detail page.
- Edit fields only while the lifecycle and your permission still allow direct editing.
- Move the record through its workflow using actions such as **Post Payment**, **Reverse Payment** rather than changing status fields manually.

## Verified operating behavior

- Create Payment records the customer, active payment method, amount, currency, payment date, optional external reference/proof and notes. Payment proof is a creation-time evidence upload.
- Posting is the financial boundary. The Post action lets the operator allocate the payment to eligible issued invoices for the same customer; only invoices with outstanding value are offered.
- When Payment Create is opened from an invoice, the customer/invoice context can prefill the intended allocation, but the operator still reviews the amount before posting.
- Any posted excess that is not allocated to an invoice remains customer-deposit context rather than being silently discarded.
- Posted payment records are not normally edited through the form. Reverse uses an explicit financial reversal path and is available only for posted, non-reversed payments with permission.

## List page

<IerpScreenshot id="sales-payment-index" title="Payments — List page" description="The main Payments list. Use it to search, filter, sort, open records and run the actions available to your role." path="Sales → Billing & Payments → Payments" imageSrc="/images/en/sales/sales-payment-index.png" />

The main Payments list. Use it to search, filter, sort, open records and run the actions available to your role.

### Read collection and allocation separately

**Received** is the payment amount, **Applied to invoices** is what has been allocated, and **Available customer deposit** is the posted remainder still available for future eligible invoices. The Customer deposits view focuses records with that usable remainder.

Draft payments can be posted; posted payments are immutable through the normal edit form. Export CSV downloads the current filtered/searched payment set.

The screen is a working queue: search and filters narrow records, saved or preset views focus common tasks, and row actions open the record without changing its lifecycle by themselves.

### Main table information

The list can expose the following record information, depending on permissions and table configuration:

- **Payment Number**
- **Customer Company Name**
- **Source**
- **Amount**
- **Applied Amount**
- **Customer Deposit**
- **Allocations Count**
- **Status**
- **Payment Date**

### Global search

This resource participates in dashboard search through:

- Payment Number
- External Reference
- Customer Company Name
- Customer Customer Code

## Create page

<IerpScreenshot id="sales-payment-create" title="Payments — Create page" description="The Payments creation form. Complete the required business information and review dependent fields before saving or submitting." path="Sales → Billing & Payments → Payments → Create page" imageSrc="/images/en/sales/sales-payment-create.png" />

The Payments creation form. Complete the required business information and review dependent fields before saving or submitting.

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
| Payment Method | Select | Selects the payment method from the configured choices that are valid for this record and its current context. |
| Amount | TextInput | Stores or displays the monetary amount used by totals, posting or performance calculations according to the owning workflow. |
| Payment Date | DatePicker | Records the payment date used by the workflow for scheduling, validity, due-date logic or historical context. |
| Payment Proof | FileUpload | Attaches the payment proof document or evidence required by the workflow. |

### Workflow, approval & evidence

| Field / area | Control | How to use it |
| --- | --- | --- |
| Notes | Textarea | Stores operator context that should remain visible with the record; do not use notes as a substitute for required structured fields. |

### Additional information

| Field / area | Control | How to use it |
| --- | --- | --- |
| External Reference | TextInput | Stores an external or source reference so this record can be reconciled with the originating business document/system. |

### Before saving

Verify required identities, dates, currency/UOM values, line-level quantities and any evidence requested by the workflow. Dependent selectors can change after choosing a customer, supplier, product or warehouse.

## Record details

<IerpScreenshot id="sales-payment-view" title="Payments — Record details" description="The Payments detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions." path="Sales → Billing & Payments → Payments → Record details" imageSrc="/images/en/sales/sales-payment-view.png" />

The Payments detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions.

### Posting and allocation

Posting opens an allocation form containing eligible issued invoices for the same customer and the amount to apply to each. When the payment was opened from an invoice, IERP can prefill that invoice allocation up to the smaller of the payment amount and current outstanding balance.

After posting, the allocation table lets you compare the applied amount with the invoice's current outstanding balance. Any unused posted amount remains customer deposit. Reversal uses the explicit **Reverse payment** action rather than editing a posted record.

### Information shown on the detail screen

The detail/infolist implementation can expose:

- **Customer Company Name**
- **Payment Method Name**
- **Source Label**
- **Amount**
- **Status**
- **Payment Date**
- **External Reference**
- **Posted At**
- **Notes**
- **Allocations**
- **Invoice Invoice Number**
- **Current Outstanding**
- **Provider Transaction Provider**
- **Provider Transaction Purpose Type**
- **Provider Status**
- **Settlement Status**
- **Provider Transaction Checkout Session**
- **Provider Transaction Payment Intent**
- **Recognized Tax Total**
- **Tax Recognition Entries**
- **Recognised Tax Amount**
- **No Tax Recognition**
- **Customer Deposit Available**
- **Deposit Explanation**
- **Deposit Empty State**
- **Received Summary**
- **Applied Summary**
- **Deposit Summary**
- **Payment Method Summary**
- **Reversed At**
- **Reversed By Name**

## Edit page

<IerpScreenshot id="sales-payment-edit" title="Payments — Edit page" description="The Payments editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary." path="Sales → Billing & Payments → Payments → Edit page" imageSrc="/images/en/sales/sales-payment-edit.png" />

The Payments editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary.

### Form fields and sections

### Identity & master data

| Field / area | Control | How to use it |
| --- | --- | --- |
| Customer | Select | Selects the customer that owns the commercial, service or financial record. Customer defaults can influence currency, price list, payment terms and assigned sales context. |

### Commercial terms & value

| Field / area | Control | How to use it |
| --- | --- | --- |
| Payment Method | Select | Selects the payment method from the configured choices that are valid for this record and its current context. |
| Amount | TextInput | Stores or displays the monetary amount used by totals, posting or performance calculations according to the owning workflow. |
| Payment Date | DatePicker | Records the payment date used by the workflow for scheduling, validity, due-date logic or historical context. |
| Payment Proof | FileUpload | Attaches the payment proof document or evidence required by the workflow. |

### Workflow, approval & evidence

| Field / area | Control | How to use it |
| --- | --- | --- |
| Notes | Textarea | Stores operator context that should remain visible with the record; do not use notes as a substitute for required structured fields. |

### Additional information

| Field / area | Control | How to use it |
| --- | --- | --- |
| External Reference | TextInput | Stores an external or source reference so this record can be reconciled with the originating business document/system. |

### Editing rules

Editing is not a replacement for a workflow transition. Once a transaction is approved, posted, sent or otherwise locked, IERP can hide or reject edits and require a dedicated correction or reversal path.

## What happens after this step

- Posting records the money/accounting effect and synchronizes allocations.
- Tax recognition follows collection/allocation rules rather than simply invoice issue.
- Reversal is an explicit workflow and can be blocked by downstream credit/refund dependencies.

## Actions and lifecycle controls

The implementation currently exposes action definitions including:
- **Post Payment** — continues the controlled payment flow and records the financial action only when its prerequisites are satisfied.
- **Reverse Payment** — continues the controlled payment flow and records the financial action only when its prerequisites are satisfied.

High-impact actions can change stock, money, approvals or lifecycle state. Read the confirmation dialog before continuing.

## Statuses used by this record

The current status vocabulary is:

<IerpStatus tone="neutral">Draft</IerpStatus> <IerpStatus tone="success">Posted</IerpStatus> <IerpStatus tone="neutral">Reversed</IerpStatus>

Use the action buttons exposed by the record to move between statuses. The presence of a status does not mean every user may perform the transition.

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
