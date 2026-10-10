---
title: "Receipts"
module: inventory
pages: ["index", "receipts", "deliveries", "transfers", "create", "view", "edit"]
screenshots: ["inventory-inventory-operation-index", "inventory-inventory-operation-receipts", "inventory-inventory-operation-deliveries", "inventory-inventory-operation-transfers", "inventory-inventory-operation-create", "inventory-inventory-operation-view", "inventory-inventory-operation-edit"]
lastVerified: 2026-10-07
---

# Receipts, deliveries and internal transfers

<IerpPageSummary purpose="Receive goods, dispatch deliveries and move stock between warehouses with a traceable source and controlled lifecycle." useWhen="A warehouse needs to act on an existing receipt or delivery, or prepare an internal transfer." owner="Inventory" />

## When to use this page

Use **Receipts** for incoming goods, **Deliveries** for outgoing customer stock and **Internal Transfers** for movements between your warehouses. The combined **Inventory Operations** list helps trace all three types. Each record shows its current stage, business source, next action and stock impact.

This page manages physical stock work. It does not replace a purchase order, customer order, invoice, supplier bill or payment.

## How to open it

- **Inventory → Inbound → Receipts** opens the incoming queue beside Inbound Allocation.
- **Inventory → Outbound → Deliveries** opens the outgoing queue beside Outbound Fulfillment, Shipments and Packages.
- **Inventory → Operations → Internal Transfers** opens transfers beside adjustments, counts, returns, corrections, condition changes and reservations.

Your role determines which queues and actions you can use. A missing action can reflect permission or the record's stage.

## Before you start

Check the source document, product variant, unit, quantities and warehouses. Have the lot, expiry and serial information required for the product. For outbound work, check available stock and reservations; goods in transit have not yet arrived in the destination warehouse.

Purchase receipts start from the purchase order and inbound allocation flow. Customer deliveries start from the order/fulfillment flow. The standalone creation action here is **Create Internal Transfer**. It does not offer a separate receipt or customer-delivery creation flow.

## Typical tasks

1. Open the appropriate queue and find the record by reference or party. Use **Open**, **Ready** or **Done** to focus the work.
2. Open **View** and read **Current Stage**, **Business Source**, **Next Action** and **Inventory Impact** before choosing an action.
3. Review the lines and their units and tracking information. Edit permitted facts while the operation is Draft.
4. Follow the available stage action, review its confirmation and check the resulting stage and stock movements.
5. Correct completed history through the appropriate correction or return workflow.

Searching, filtering, grouping and viewing records do not change stock. Wide lists scroll horizontally; use the column manager to choose visible columns. A blank reference shown as **Assigned on confirmation** is expected before a number is allocated.

## Queues

### All operations

<IerpScreenshot id="inventory-inventory-operation-index" imageSrc="/images/en/inventory/inventory-inventory-operation-index.jpg" title="Combined inventory operations" description="Trace receipts, deliveries and transfers from a single working list." path="Inventory → Inventory Operations" />

The combined list includes type and both warehouse columns. Use a typed queue for a more focused layout. Its creation link also opens an internal transfer.

### Receipts

<IerpScreenshot id="inventory-inventory-operation-receipts" imageSrc="/images/en/inventory/inventory-inventory-operation-receipts.jpg" title="Receipts queue" description="Review incoming stock by supplier, destination warehouse and stage." path="Inventory → Inbound → Receipts" />

Open the receipt created by the inbound allocation. Review the assigned products and quantities, then complete the required tracking details. **Mark Ready** validates the receipt; it does not add on-hand stock. **Complete Receipt** receives into the destination warehouse and records the stock movement.

### Deliveries

<IerpScreenshot id="inventory-inventory-operation-deliveries" imageSrc="/images/en/inventory/inventory-inventory-operation-deliveries.jpg" title="Deliveries queue" description="Review customer, delivery type, source warehouse and dispatch stage." path="Inventory → Outbound → Deliveries" />

Ready delivery work reserves outgoing quantities without reducing on-hand. Follow the linked fulfillment/shipment work to prepare and dispatch it. **Dispatch from Warehouse** appears when the required planned shipment and permissions are present. Dispatch removes source-warehouse custody.

The completed delivery stage is displayed as **Dispatched from Warehouse**. This records warehouse departure; consult the shipment's progress for transport and customer receipt. If offered, **Generate Packing List** prepares a document and does not itself dispatch goods.

### Internal transfers

<IerpScreenshot id="inventory-inventory-operation-transfers" imageSrc="/images/en/inventory/inventory-inventory-operation-transfers.jpg" title="Internal transfers queue" description="Separate ready, traveling, partially received and completed transfers." path="Inventory → Operations → Internal Transfers" />

Use **Create Internal Transfer** to move goods between your own warehouses. Ready reserves the source quantity. **Dispatch Transfer** removes source custody and places goods in transit. Destination stock increases only as actual receipt is recorded.

## Create a transfer

<IerpScreenshot id="inventory-inventory-operation-create" imageSrc="/images/en/inventory/inventory-inventory-operation-create.jpg" title="Internal transfer creation" description="Begin with the source and destination warehouses; complete lines before creating a Draft." path="Inventory → Operations → Internal Transfers → Create Internal Transfer" />

Choose different source and destination warehouses in **Reference**. In **Operations**, add the products/variants, quantities and applicable tracking details. Review **Scheduled At** and **Notes**, then choose **Create**. Required fields must be complete. Saving a Draft does not dispatch or receive stock; continue from the saved record's stage actions.

## Review and receive

<IerpScreenshot id="inventory-inventory-operation-view" imageSrc="/images/en/inventory/inventory-inventory-operation-view.jpg" title="Operation stage and stock impact" description="A completed receipt shows the destination stock effect and its correction action." path="Inventory → Receipts → View" />

For transfers, compare dispatched and received base quantities. **Receive Transfer** shows the remaining amount in the transaction unit and initially fills it as the receipt quantity. Replace that default with the quantity physically counted at the destination. A partial receipt keeps the operation **Partially Received** while unresolved quantities remain.

If the remainder will not arrive, select the appropriate discrepancy disposition and enter a reason. Do not record an unreceived quantity as received to close the document. A transfer becomes Done when its lines are fully received or settled under the discrepancy rules.

Warehouse preparation describes picking work. Use the current stage and stock impact to determine whether posting occurred; a preparation flag alone is not proof of a completed receipt or dispatch.

## Edit a Draft

<IerpScreenshot id="inventory-inventory-operation-edit" imageSrc="/images/en/inventory/inventory-inventory-operation-edit.jpg" title="Draft purchase receipt" description="Purchase-assigned supplier, destination and demand remain controlled by the inbound source." path="Inventory → Receipts → Edit" />

Direct editing is available only in Draft. A purchase-linked receipt can lock its supplier, destination, product and demand even while Draft, because those facts come from inbound allocation. Complete the permitted warehouse details or return to the source workflow to resolve incorrect assignments. Use **Save changes** only after reviewing the allowed changes.

## Stock and financial consequences

| Stage or action | Physical effect |
| --- | --- |
| Draft / Waiting | No on-hand change. |
| Receipt Ready | Validated; incoming stock is not yet received. |
| Delivery or transfer Ready | Outbound reservations protect availability; on-hand is unchanged. |
| Complete Receipt | Destination stock increases and movement history is recorded. |
| Dispatch from Warehouse | Source stock leaves warehouse custody. |
| Dispatch Transfer | Source stock leaves; the remaining amount is in transit. |
| Receive Transfer | Destination receives only the actual counted quantity. |

Inventory owns physical quantities and tracking history. Purchasing owns purchase and inbound progress, Sales owns customer commitments and invoices, Payments owns collections/refunds, and Accounting owns financial posting. Receiving or dispatching here does not settle a supplier bill, collect a customer payment or issue an invoice.

## Cancellation and corrections

Cancellation releases outstanding reservations. Canceling a dispatched transfer restores source custody through compensating stock history; reconcile the goods' actual location before choosing it. Review the confirmation and use a correction/return path when the completed transaction requires one.

A completed receipt offers **Create Receipt Correction** when permitted; enter the required reason and continue in the correction document. A completed customer delivery can offer **Create Customer Return**, where you choose the return warehouse and continue the return workflow. Creating these documents is a separate step from posting their physical effect. Credit notes and refunds remain separate financial work.

## When something goes wrong

If Ready or dispatch fails, check product/unit validity, quantity, tracking requirements, source availability and reservations. For missing delivery dispatch, review the planned shipment as well as permissions. For partial transfers, reconcile dispatched, received and remaining quantities before receiving again. For incorrect purchase receipt assignments, revisit inbound allocation. Preserve completed movement history and use the appropriate correction rather than trying to edit a posted quantity.

Related: [Inbound Allocation](/screens/inventory/purchase-inbound), [Outbound Fulfillment](/screens/inventory/outbound-fulfillment), [Stock Levels](/screens/inventory/stock-level), [Stock Movements](/screens/inventory/stock-movement), [Receipt Corrections](/screens/inventory/inventory-correction), [Returns](/screens/inventory/return).

## Live View diagnostics

The View page includes read-only operational diagnostics: outstanding count lines/recounts/variances or blocked operations, with next steps. Workflow buttons and approvals remain authoritative. Diagnostics never post stock.
