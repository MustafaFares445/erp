# Shipments

<IerpPageSummary purpose="Follow shipment preparation, in-transit status, delivery evidence and arrival." useWhen="Review, filter or act on the corresponding Inventory records according to stage and permission." owner="Inventory" />

## When to use this page

Review, filter or act on the corresponding Inventory records according to stage and permission.

## Typical tasks

- Follow the documented “Operator tasks” instructions below.
- Follow the documented “Business rules and troubleshooting” instructions below.
- Follow the documented “Screen references” instructions below.

## When to use
Follow shipment preparation, in-transit status, delivery evidence and arrival.

## Operator tasks
1. Open the Inventory navigation workspace and verify your current language, warehouse scope and selected record.
2. Use the list search, status filters or an appropriate detail tab to find the correct document.
3. Review quantities, units, source links, stage, ownership and permitted actions before editing.
4. Confirm the resulting record state and check stock movements after any separately authorized physical posting.

## Business rules and troubleshooting
Shipment progress must be reconciled with the linked Inventory delivery; transport updates alone do not post stock.

If the action is missing, check your role and the record lifecycle. Blank tables may reflect filters or missing demo records. A rendered route is not proof that a physical workflow has been completed.

## View: dispatch versus arrival confirmation

The View panel distinguishes Planned, In Transit and Arrived. Review tracking number, customer, warehouse, confirmation actor/date, delivery evidence photos and attachments. Dispatch posts the outbound stock movement; Confirm Arrival records arrival only for an in-transit shipment whose Delivery is Done. This confirmation does not deduct stock again. The detail-page Confirm records arrival evidence.

## Screen references

<IerpScreenshot id="inventory-shipment-index" title="Shipments — List page" description="Follow shipment preparation, in-transit status, delivery evidence and arrival." path="Inventory" imageSrc="/images/en/inventory/inventory-shipment-index.png" />

<IerpScreenshot id="inventory-shipment-view" title="Shipments — Record details" description="Follow shipment preparation, in-transit status, delivery evidence and arrival." path="Inventory" />
