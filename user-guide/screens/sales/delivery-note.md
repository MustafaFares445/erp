---
title: "Delivery Notes"
module: sales
pages: ["index", "view"]
screenshots: ["sales-delivery-note-index", "sales-delivery-note-view"]
lastVerified: 2026-10-07
---

# Delivery Notes

<IerpNavigationPath :items='["Sales", "Fulfillment", "Delivery Notes"]' />

<IerpPageSummary purpose="Review the commercial delivery document and delivery evidence produced from completed customer fulfillment." useWhen="You need to work with delivery notes records or follow their lifecycle." owner="Sales" />

## Overview

Review the commercial delivery document and delivery evidence produced from completed customer fulfillment.

Sales controls the customer commercial lifecycle from quotation and confirmed demand through fulfillment, billing, collection and credit correction.

## How to open it

Use the dashboard module switcher and follow **Sales → Fulfillment → Delivery Notes**. The exact visible options depend on your role.

## Screens in this area

| Screen | What you use it for |
| --- | --- |
| List page | The main Delivery Notes list. Use it to search, filter, sort, open records and run the actions available to your role. |
| Record details | The Delivery Notes detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions. |

## When to use this page

Use **Delivery Notes** when your work belongs to this Sales responsibility. The page keeps the record in the module that owns the business fact instead of duplicating it in another area.

## Typical tasks

- Find an existing record using search, views and filters, then open it from the working list.
- Review the record's current status, source relationships, totals/evidence and related work from the detail page.
- Move the record through its workflow using actions such as **Create Invoice** rather than changing status fields manually.

## Verified operating behavior

- Delivery Notes are a Sales-facing view of Inventory delivery operations. The record is an InventoryOperation filtered to operation type Delivery, so Inventory remains the physical stock authority.
- The list shows delivery number, customer, source warehouse, schedule, stage and whether the completed delivery has already been invoiced. The Uninvoiced filter focuses Done deliveries that have no invoice link.
- The detail page shows the source warehouse, customer, stage, line quantities/UOM, warehouse-preparation state and related documents.
- Create Invoice appears only when the delivery is Done, has not already been invoiced and the user may create invoices. It creates a Draft invoice from the delivery and links the two records.
- Generating/using the packing list and completing physical delivery remain Inventory/Logistics responsibilities; Sales should not edit delivery quantities from this screen.

## List page

<IerpScreenshot id="sales-delivery-note-index" title="Delivery Notes — List page" description="The main Delivery Notes list. Use it to search, filter, sort, open records and run the actions available to your role." path="Sales → Fulfillment → Delivery Notes" imageSrc="/images/en/sales/sales-delivery-note-index.png" />

The main Delivery Notes list. Use it to search, filter, sort, open records and run the actions available to your role.

### Delivery working views

Use **Ready to dispatch**, **Delivered, not invoiced**, **Delivered** and **Cancelled** views to separate logistics progress from billing follow-up. The **Uninvoiced** filter means the Inventory delivery is Done and no invoice-delivery link exists yet.

**Create invoice** is deliberately conditional: it appears only for a Done, uninvoiced delivery when the operator can create invoices.

The screen is a working queue: search and filters narrow records, saved or preset views focus common tasks, and row actions open the record without changing its lifecycle by themselves.

### Main table information

The list can expose the following record information, depending on permissions and table configuration:

- **Operation Number**
- **Customer Company Name**
- **Source Warehouse Name**
- **Scheduled At**
- **Stage**
- **Invoiced**

### Filters

Use filters to narrow the working set without changing records:

- **Uninvoiced** (Filter)

## Record details

<IerpScreenshot id="sales-delivery-note-view" title="Delivery Notes — Record details" description="The Delivery Notes detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions." path="Sales → Fulfillment → Delivery Notes → Record details" imageSrc="/images/en/sales/sales-delivery-note-view.png" />

The Delivery Notes detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions.

### What the detail page represents

This page reads the Inventory delivery operation: reference, stage, customer, source warehouse, schedule, notes, delivered lines and warehouse-preparation state. Related documents show how the operation connects back to the order and forward to shipment/invoice work.

**Generate Packing List** produces operational output. **Create invoice**, when eligible, creates a Draft invoice from this completed delivery and redirects to that invoice.

### Information shown on the detail screen

The detail/infolist implementation can expose:

- **Operation Number**
- **Stage**
- **Customer Company Name**
- **Source Warehouse Name**
- **Scheduled At**
- **Notes**
- **Lines**
- **Product Variant SKU**
- **Quantity**
- **Is Picked**

## Actions and lifecycle controls

The implementation currently exposes action definitions including:
- **Create Invoice** — creates the linked invoice through the supported workflow and preserves this record as its source.

High-impact actions can change stock, money, approvals or lifecycle state. Read the confirmation dialog before continuing.

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
