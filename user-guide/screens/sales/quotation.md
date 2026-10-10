---
title: "Quotations"
module: sales
pages: ["index", "create", "view", "edit"]
screenshots: ["sales-quotation-index", "sales-quotation-create", "sales-quotation-view", "sales-quotation-edit"]
lastVerified: 2026-10-07
---

# Quotations

<IerpNavigationPath :items='["Sales", "Selling", "Quotations"]' />

<IerpPageSummary purpose="Create, send, review and convert customer quotations while preserving pricing, validity and customer-decision history before demand becomes a Sales Order." useWhen="You need to work with quotations records or follow their lifecycle." owner="Sales" />

## Overview

Create, send, review and convert customer quotations while preserving pricing, validity and customer-decision history before demand becomes a Sales Order.

Sales controls the customer commercial lifecycle from quotation and confirmed demand through fulfillment, billing, collection and credit correction.

## How to open it

Use the dashboard module switcher and follow **Sales → Selling → Quotations**. The exact visible options depend on your role.

## Screens in this area

| Screen | What you use it for |
| --- | --- |
| List page | The main Quotations list. Use it to search, filter, sort, open records and run the actions available to your role. |
| Create page | The Quotations creation form. Complete the required business information and review dependent fields before saving or submitting. |
| Record details | The Quotations detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions. |
| Edit page | The Quotations editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary. |

## When to use this page

- Use a quotation when the customer needs a commercial offer before committing to an order.
- Use it when price provenance, validity, product/UOM selection, tax and any below-floor approval must be preserved before the sale is confirmed.
- Do not use a quotation to move stock. Physical stock changes begin later through fulfillment and Inventory.

## Typical tasks

- Find an existing record using search, views and filters, then open it from the working list.
- Create a new record from the page action and complete the required business sections before saving.
- Review the record's current status, source relationships, totals/evidence and related work from the detail page.
- Edit fields only while the lifecycle and your permission still allow direct editing.
- Move the record through its workflow using actions such as **Send**, **Record Decision**, **Convert**, **Requote**, **Generate PDF** rather than changing status fields manually.

## Verified operating behavior

- Selecting a customer refreshes the Active Pricing Tier card. Selecting a product can reveal a Product Variant/SKU selector when the product has multiple variants; the chosen variant then constrains the sale UOM and resolved price.
- Use Tier Price keeps the line tied to the eligible resolved pricing tier. If a manually entered price is below the permitted floor, the form requires a reason and stores the approved/linked floor-override provenance separately from the visible reason.
- Send is available only from Draft and makes the quotation a customer-facing offer; direct editing is no longer the normal path after sending.
- Record Decision is available on Sent quotations. Accepted, Rejected and Changes Requested are explicit outcomes; the decision date is required, and Rejected or Changes Requested also require a decision note.
- Convert is available only when the quotation is eligible for conversion. Browser QA verified that one accepted quotation produced exactly one Confirmed Sales Order while preserving quantity, UOM, unit price, tax and pricing provenance.
- Conversion does not reserve stock, create stock movements or reduce on-hand quantity. Inventory effects happen later through fulfillment/Inventory.
- Re-quote is the correction path for an expired quotation or one where the customer requested changes; it creates a new linked quotation instead of rewriting the prior decision history.
- Generate PDF creates or regenerates the document for Sent/Accepted quotations. After generation, use Preview PDF or Download in the Document section. Generation is separate from opening, downloading or emailing a document.

## List page

<IerpScreenshot id="sales-quotation-index" title="Quotations — List page" description="The main Quotations list. Use it to search, filter, sort, open records and run the actions available to your role." path="Sales → Selling → Quotations" imageSrc="/images/en/sales/sales-quotation-index.png" />

The main Quotations list. Use it to search, filter, sort, open records and run the actions available to your role.

### Working views and export

Use the status tabs to focus Draft, Sent/Awaiting decision, Accepted, Expiring soon, Open, Converted, or Rejected/Cancelled quotations. The favorite column and Starred view are personal working aids and do not change the quotation lifecycle.

The list shows quotation number, customer, status, stock-coverage context, issue/expiry dates and grand total. Row actions are state-sensitive: Send, Record Decision, Convert to Order, Create Revised Quotation/Requote and ordinary View/Edit appear only where the record and permission allow them.

**Export CSV** downloads the current filtered/searched table result and records an audit event for the export.

The screen is a working queue: search and filters narrow records, saved or preset views focus common tasks, and row actions open the record without changing its lifecycle by themselves.

### Main table information

The list can expose the following record information, depending on permissions and table configuration:

- **Quotation Number**
- **Customer Company Name**
- **Status**
- **Reservation Coverage**
- **Issue Date**
- **Expires At**
- **Grand Total**

### Global search

This resource participates in dashboard search through:

- Quotation Number
- Customer Company Name
- Customer Customer Code
- Notes

## Create page

<IerpScreenshot id="sales-quotation-create" title="Quotations — Create page" description="The Quotations creation form. Complete the required business information and review dependent fields before saving or submitting." path="Sales → Selling → Quotations → Create page" imageSrc="/images/en/sales/sales-quotation-create.png" />

The Quotations creation form. Complete the required business information and review dependent fields before saving or submitting.

### Dependent line behavior

1. Select the customer first so IERP can resolve the active pricing tier and commercial defaults.
2. Select a product. If it has one active variant, IERP resolves that variant automatically. If it has several variants, a required **Product variant** / SKU selector appears.
3. The selected variant supplies the eligible active sale units and the default sale UOM.
4. With **Use tier price** enabled, Unit price is resolved and read-only. Disable it only when a justified manual price is required.
5. If that manual price is below the variant floor, the authoritative save validation still requires an approved exception. An operator with price-floor approval sees a required reason field for creating that approval evidence.
6. Enter tax and optional line description, then add more lines as needed. At least one line is required.

### Before you create the record

- The customer must exist and be eligible for the commercial flow.
- Products/variants and units of measure must be active.
- Review the customer's pricing tier and payment-term defaults before entering lines.

### Form fields and sections

### Identity & master data

| Field / area | Control | How to use it |
| --- | --- | --- |
| Customer | Select | Selects the customer that owns the commercial, service or financial record. Customer defaults can influence currency, price list, payment terms and assigned sales context. |

### Commercial terms & value

| Field / area | Control | How to use it |
| --- | --- | --- |
| Active Pricing Tier | Placeholder | Shows the customer pricing tier currently resolved for this transaction. It is context for price calculation rather than a free-form price field. |
| Payment Term | Select | Applies the agreed payment term that controls expected due-date behavior for billing or supplier liability. |
| Use Tier Price | Checkbox | When enabled, the line uses the eligible resolved tier price rather than treating the entered price as an unrelated manual value. |
| Unit Price | TextInput | Records the selling unit price resolved or entered for the line before discounts, tax and totals are applied. |
| Tax Amount | TextInput | Shows or captures the tax amount calculated for the line/document under the active tax rules. Review the tax definition rather than overriding totals casually. |
| Price Floor Override | Hidden | System context linking an approved below-floor pricing exception to the transaction. It is not edited as ordinary commercial data. |
| Price Floor Override Reason | Textarea | Explains why a below-floor price is being requested or used so the exception remains auditable. |

### Product, quantity & inventory context

| Field / area | Control | How to use it |
| --- | --- | --- |
| Product Variant Image | Placeholder | Shows the selected variant image so the operator can visually verify the exact SKU before entering quantity or price. |
| Product | Select | Selects the commercial product. Variant, tracking, UOM and pricing choices can depend on this selection. |
| Product Variant | Select | Selects the concrete SKU/variant used for pricing, stock tracking and downstream fulfillment. |
| Unit | Select | Sets the unit of measure used by this line. IERP preserves conversion rules between commercial/purchase units and normalized inventory quantities. |
| Quantity | TextInput | Records the requested quantity for this line. Downstream availability, totals, reservations or replenishment can depend on it. |

### Dates & scheduling

| Field / area | Control | How to use it |
| --- | --- | --- |
| Issue Date | DatePicker | Records the commercial issue date from which the quotation/invoice document is considered issued for its lifecycle. |
| Expires At | DatePicker | Sets when the commercial offer expires if the customer has not accepted it before that time. |

### Workflow, approval & evidence

| Field / area | Control | How to use it |
| --- | --- | --- |
| Employee | Select | Selects the employee/salesperson responsible for the commercial or field-work context of this record. |

### Additional information

| Field / area | Control | How to use it |
| --- | --- | --- |
| Lines | Repeater | Contains the transaction line items. Each row carries its own product, quantity, UOM and commercial or tracking context. |
| Description | RichEditor | Stores the business description shown with the record or line. Use it for meaningful transaction context, not to replace structured fields. |

### Before saving

Verify required identities, dates, currency/UOM values, line-level quantities and any evidence requested by the workflow. Dependent selectors can change after choosing a customer, supplier, product or warehouse.

## Record details

<IerpScreenshot id="sales-quotation-view" title="Quotations — Record details" description="The Quotations detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions." path="Sales → Selling → Quotations → Record details" imageSrc="/images/en/sales/sales-quotation-view.png" />

The Quotations detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions.

### Customer decision and conversion

A Sent quotation exposes **Record Decision**. Accepted needs the decision date; Rejected and Changes Requested require both the date and a note. Changes Requested/expired quotations can be re-quoted without overwriting the prior offer.

Only an Accepted quotation is convertible. Conversion is one-time and creates a **Confirmed Sales Order** with the quoted line quantity, UOM, price, tax and pricing provenance preserved. It does not create stock movements or reservations.

For Sent/Accepted quotations, **Generate PDF** queues document generation. Once generation finishes, the **Document** section shows **Preview PDF** and **Download**. If it still says **Not generated yet**, reload after processing completes or ask the administrator to check document generation. Generating a PDF does not email it to the customer.

### Information shown on the detail screen

The detail/infolist implementation can expose:

- **Quotation PDF**
- **Quotation Number**
- **Customer Company Name**
- **Status**
- **Grand Total**
- **Workflow Summary**
- **Issue Date**
- **Expires At**
- **Customer Quotation Request Request Number**
- **Decision Status**
- **Decided At**
- **Decided By Name**
- **Decision Note**
- **Lines**
- **Product Variant Main Image**
- **Product Variant SKU**
- **Quantity**
- **Unit Name**
- **Unit Price**
- **Tax Amount**
- **Line Total**
- **Description**
- **Resolved Price Source**
- **Resolved Price Tier Name**
- **List Price Minor**
- **Floor Price Minor**
- **Floor Override Status**
- **Responses**
- **Response Summary**
- **Recorded By Name**
- **Note**
- **Subtotal**
- **Tax Total**

## Edit page

<IerpScreenshot id="sales-quotation-edit" title="Quotations — Edit page" description="The Quotations editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary." path="Sales → Selling → Quotations → Edit page" imageSrc="/images/en/sales/sales-quotation-edit.png" />

The Quotations editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary.

### Form fields and sections

### Identity & master data

| Field / area | Control | How to use it |
| --- | --- | --- |
| Customer | Select | Selects the customer that owns the commercial, service or financial record. Customer defaults can influence currency, price list, payment terms and assigned sales context. |

### Commercial terms & value

| Field / area | Control | How to use it |
| --- | --- | --- |
| Active Pricing Tier | Placeholder | Shows the customer pricing tier currently resolved for this transaction. It is context for price calculation rather than a free-form price field. |
| Payment Term | Select | Applies the agreed payment term that controls expected due-date behavior for billing or supplier liability. |
| Use Tier Price | Checkbox | When enabled, the line uses the eligible resolved tier price rather than treating the entered price as an unrelated manual value. |
| Unit Price | TextInput | Records the selling unit price resolved or entered for the line before discounts, tax and totals are applied. |
| Tax Amount | TextInput | Shows or captures the tax amount calculated for the line/document under the active tax rules. Review the tax definition rather than overriding totals casually. |
| Price Floor Override | Hidden | System context linking an approved below-floor pricing exception to the transaction. It is not edited as ordinary commercial data. |
| Price Floor Override Reason | Textarea | Explains why a below-floor price is being requested or used so the exception remains auditable. |

### Product, quantity & inventory context

| Field / area | Control | How to use it |
| --- | --- | --- |
| Product Variant Image | Placeholder | Shows the selected variant image so the operator can visually verify the exact SKU before entering quantity or price. |
| Product | Select | Selects the commercial product. Variant, tracking, UOM and pricing choices can depend on this selection. |
| Product Variant | Select | Selects the concrete SKU/variant used for pricing, stock tracking and downstream fulfillment. |
| Unit | Select | Sets the unit of measure used by this line. IERP preserves conversion rules between commercial/purchase units and normalized inventory quantities. |
| Quantity | TextInput | Records the requested quantity for this line. Downstream availability, totals, reservations or replenishment can depend on it. |

### Dates & scheduling

| Field / area | Control | How to use it |
| --- | --- | --- |
| Issue Date | DatePicker | Records the commercial issue date from which the quotation/invoice document is considered issued for its lifecycle. |
| Expires At | DatePicker | Sets when the commercial offer expires if the customer has not accepted it before that time. |

### Workflow, approval & evidence

| Field / area | Control | How to use it |
| --- | --- | --- |
| Employee | Select | Selects the employee/salesperson responsible for the commercial or field-work context of this record. |

### Additional information

| Field / area | Control | How to use it |
| --- | --- | --- |
| Lines | Repeater | Contains the transaction line items. Each row carries its own product, quantity, UOM and commercial or tracking context. |
| Description | RichEditor | Stores the business description shown with the record or line. Use it for meaningful transaction context, not to replace structured fields. |

### Editing rules

Editing is not a replacement for a workflow transition. Once a transaction is approved, posted, sent or otherwise locked, IERP can hide or reject edits and require a dedicated correction or reversal path.

## What happens after this step

- Saving creates a Draft quotation only; it does not reserve or reduce stock and does not post accounting.
- Send moves the commercial offer to the customer-facing stage.
- An Accepted quotation may be converted once into a Confirmed Sales Order.
- Rejected, expired, cancelled or changes-requested quotations do not create an order. Re-quote creates a new quotation with provenance to the prior one.

## Actions and lifecycle controls

The implementation currently exposes action definitions including:
- **Send** — moves the record into the sent/customer-or-supplier-facing stage after validating required commercial data.
- **Record Decision** — records the external or reviewer decision and its note/evidence without bypassing the lifecycle.
- **Convert** — creates the next owning-domain document from the current accepted/approved source while preserving provenance.
- **Requote** — creates a new quotation attempt linked to the previous quotation instead of rewriting the prior decision history.
- **Generate PDF** — queues generation or regeneration of the printable business document; the action does not by itself imply an immediate browser preview/download.

High-impact actions can change stock, money, approvals or lifecycle state. Read the confirmation dialog before continuing.

## Statuses used by this record

The current status vocabulary is:

<IerpStatus tone="neutral">Draft</IerpStatus> <IerpStatus tone="primary">Sent</IerpStatus> <IerpStatus tone="success">Accepted</IerpStatus> <IerpStatus tone="danger">Rejected</IerpStatus> <IerpStatus tone="danger">Expired</IerpStatus> <IerpStatus tone="success">Converted to Order</IerpStatus> <IerpStatus tone="danger">Cancelled</IerpStatus> <IerpStatus tone="neutral">Changes Requested</IerpStatus>

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
