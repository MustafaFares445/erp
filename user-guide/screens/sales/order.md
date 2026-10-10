---
title: "Orders"
module: sales
pages: ["index", "create", "view", "edit"]
screenshots: ["sales-order-index", "sales-order-create", "sales-order-view", "sales-order-edit"]
lastVerified: 2026-10-07
---

# Orders

<IerpNavigationPath :items='["Sales", "Selling", "Orders"]' />

<IerpPageSummary purpose="Manage confirmed customer demand, availability, procurement coverage and the release boundary into fulfillment." useWhen="You need to work with orders records or follow their lifecycle." owner="Sales" />

## Overview

Manage confirmed customer demand, availability, procurement coverage and the release boundary into fulfillment.

Sales controls the customer commercial lifecycle from quotation and confirmed demand through fulfillment, billing, collection and credit correction.

## How to open it

Use the dashboard module switcher and follow **Sales → Selling → Orders**. The exact visible options depend on your role.

## Screens in this area

| Screen | What you use it for |
| --- | --- |
| List page | The main Orders list. Use it to search, filter, sort, open records and run the actions available to your role. |
| Create page | The Orders creation form. Complete the required business information and review dependent fields before saving or submitting. |
| Record details | The Orders detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions. |
| Edit page | The Orders editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary. |

## When to use this page

- Use Sales Orders for confirmed customer demand, whether converted from an accepted quotation or created through an allowed direct-order path.
- The order is the Sales-owned commercial commitment that later hands work to fulfillment and shortage procurement.

## Typical tasks

- Find an existing record using search, views and filters, then open it from the working list.
- Create a new record from the page action and complete the required business sections before saving.
- Review the record's current status, source relationships, totals/evidence and related work from the detail page.
- Edit fields only while the lifecycle and your permission still allow direct editing.
- Move the record through its workflow using actions such as **Confirm Order**, **Release Order**, **Next Step**, **Cancel Order**, **Short Close Order** rather than changing status fields manually.

## Verified operating behavior

- Create Order is a three-step wizard: Customer & destination → Products & commercial quantities → Commercial terms & review.
- Customer selection controls eligible delivery addresses. The address is snapshotted into the order so later master-data edits do not rewrite the destination that was committed for this transaction.
- Product availability shown during creation is advisory only. Saving or confirming the Sales Order does not reserve stock or move inventory.
- Confirm freezes the customer-facing quantity, UOM, price and tax evidence. Release is a separate action that hands confirmed demand to Logistics and still does not itself create a delivery, reservation or on-hand movement.
- The detail page separates commercial status from operational availability and shows the cross-module journey: Quotation → Order → Logistics → Delivery → Invoice → Payment → Completion.
- The Next Step action is dynamic. Depending on current progress it can route the operator to supply requirements, stock allocation, dispatch, arrival confirmation, invoice creation/issue or payment.
- Short Close is available on Released orders with remaining unplanned demand. It records exact quantities being abandoned plus a mandatory reason; it does not silently change the originally ordered quantity.
- Cancel requires a reason. Direct Edit is available only while the order remains Draft; the edit form is intentionally limited to requested delivery date, payment term and notes.

## List page

<IerpScreenshot id="sales-order-index" title="Orders — List page" description="The main Orders list. Use it to search, filter, sort, open records and run the actions available to your role." path="Sales → Selling → Orders" imageSrc="/images/en/sales/sales-order-index.png" />

The main Orders list. Use it to search, filter, sort, open records and run the actions available to your role.

### Reading the order queue

The list combines commercial status with **Availability**, **Milestone**, payment status and the requested date. Use the Active, Awaiting fulfillment and Requires attention views to find orders that are waiting on operational work.

The emphasized row action represents the projected **Next step**. It can navigate to supply resolution, Logistics allocation/dispatch/arrival, invoicing or payment. It is a navigation handoff to the owning module, not an in-table shortcut that bypasses that module.

The screen is a working queue: search and filters narrow records, saved or preset views focus common tasks, and row actions open the record without changing its lifecycle by themselves.

### Main table information

The list can expose the following record information, depending on permissions and table configuration:

- **Order Number**
- **Customer Company Name**
- **Status**
- **Availability State**
- **Workflow Milestone**
- **Grand Total**
- **Payment Status**
- **Scheduled At**

### Global search

This resource participates in dashboard search through:

- Order Number
- Customer Company Name
- Customer Customer Code
- Quotation Quotation Number

## Create page

<IerpScreenshot id="sales-order-create" title="Orders — Create page" description="The Orders creation form. Complete the required business information and review dependent fields before saving or submitting." path="Sales → Selling → Orders → Create page" imageSrc="/images/en/sales/sales-order-create.png" />

The Orders creation form. Complete the required business information and review dependent fields before saving or submitting.

### Three-step order wizard

#### 1. Customer & destination

Select **Customer** first. Choose an active delivery address belonging to that customer when addresses exist, optionally set the requested delivery date, and assign the responsible salesperson. Warehouse allocation is intentionally absent here.

#### 2. Products & commercial quantities

Add one or more product variants, choose an eligible sale **UOM**, and enter customer quantity. IERP shows the resolved unit-price preview and current available base quantity as advice. Saving or confirming here does **not** reserve or move that stock.

#### 3. Commercial terms & review

Select the payment term, add order notes, and review the document. Users with confirmation permission can enable **Save and confirm commercial order**. Confirmation freezes the commercial quantity/UOM/price/tax evidence, but Logistics still requires an explicit Release before it starts warehouse execution.

After creation, continue from the Order detail/list actions; never enter warehouse, lot, serial or shipment data into the Sales wizard.

## Record details

<IerpScreenshot id="sales-order-view" title="Orders — Record details" description="The Orders detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions." path="Sales → Selling → Orders → Record details" imageSrc="/images/en/sales/sales-order-view.png" />

The Orders detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions.

### Information shown on the detail screen

The detail/infolist implementation can expose:

- **Order Number**
- **Customer Company Name**
- **Status**
- **Grand Total**
- **Journey**
- **Requested**
- **Planned**
- **Dispatched**
- **Arrived**
- **Remaining To Plan**
- **Availability State**
- **Quotation Quotation Number**
- **Availability**
- **Current Stage**
- **Scheduled At**
- **Payment Term Name**
- **Subtotal**
- **Tax Total**
- **Lines**
- **Product Variant Main Image**
- **Product Variant SKU**
- **Line Total**
- **Quantity**
- **Unit Price**
- **Fulfillment**
- **Tax Amount**
- **Short Closed Base Quantity**
- **Resolved Price Source**
- **Resolved Price Tier Name**
- **List Price Minor**
- **Floor Price Minor**
- **Floor Override Status**
- **Logistics Empty**
- **Deliveries**
- **Operation Number**
- **Source Warehouse Name**
- **Stage**
- **Shipments**
- **Tracking Number**
- **Warehouse Name**

## Edit page

<IerpScreenshot id="sales-order-edit" title="Orders — Edit page" description="The Orders editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary." path="Sales → Selling → Orders → Edit page" imageSrc="/images/en/sales/sales-order-edit.png" />

The Orders editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary.

### Form fields and sections

### Commercial terms & value

| Field / area | Control | How to use it |
| --- | --- | --- |
| Payment Term | Select | Applies the agreed payment term that controls expected due-date behavior for billing or supplier liability. |

### Dates & scheduling

| Field / area | Control | How to use it |
| --- | --- | --- |
| Scheduled At | DatePicker | Records the scheduled at used by the workflow for scheduling, validity, due-date logic or historical context. |

### Workflow, approval & evidence

| Field / area | Control | How to use it |
| --- | --- | --- |
| Notes | Textarea | Stores operator context that should remain visible with the record; do not use notes as a substitute for required structured fields. |

### Editing rules

Editing is not a replacement for a workflow transition. Once a transaction is approved, posted, sent or otherwise locked, IERP can hide or reject edits and require a dedicated correction or reversal path.

## What happens after this step

- Confirming establishes committed demand; releasing is the operational handoff.
- Release can synchronize procurement requirements for quantities not covered by available Inventory.
- The order itself does not directly post warehouse stock or the general ledger.

## Actions and lifecycle controls

The implementation currently exposes action definitions including:
- **Confirm Order** — executes this named workflow action after validating the current status, permission and prerequisites.
- **Release Order** — releases eligible work to the next operational stage after validation of readiness and permissions.
- **Next Step** — executes this named workflow action after validating the current status, permission and prerequisites.
- **Cancel Order** — executes this named workflow action after validating the current status, permission and prerequisites.
- **Short Close Order** — executes this named workflow action after validating the current status, permission and prerequisites.

High-impact actions can change stock, money, approvals or lifecycle state. Read the confirmation dialog before continuing.

## Statuses used by this record

The current status vocabulary is:

<IerpStatus tone="neutral">Draft</IerpStatus> <IerpStatus tone="neutral">Confirmed</IerpStatus> <IerpStatus tone="primary">Released</IerpStatus> <IerpStatus tone="success">Closed</IerpStatus> <IerpStatus tone="danger">Cancelled</IerpStatus>

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
