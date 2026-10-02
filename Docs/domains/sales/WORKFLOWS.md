# Sales Workflows

---
status: canonical
owner: sales
last_verified: 2026-10-02
verified_against: Sales services and current lifecycle enums
---

## Quotation to Order

`Draft Quotation -> Sent -> Accepted -> Convert -> Confirmed Sales Order`

Rejected/expired/cancelled/changes-requested paths do not create an order. Conversion is one-time.

## Direct Order

`Draft -> Confirm -> Release`

Release is the operational handoff point.

## Released Order Fulfillment

1. determine ordered/planned/remaining quantity;
2. cover what current Inventory availability can satisfy;
3. create procurement requirements for shortage;
4. Purchasing sources shortage;
5. Logistics/Inventory execute delivery;
6. start completion window when fulfillment/shipments/procurement conditions are satisfied;
7. customer closes with evidence or scheduler auto-closes after deadline.

## Invoicing

Invoices can be created from delivery source(s) or standalone through `InvoiceService`. Issue/send are explicit actions. Payment state is separate from invoice document status.

## Payment Relationship

`Issued Invoice -> Payments allocation -> invoice/order balance sync -> proportional tax recognition/accounting`

Sales exposes invoice balance behavior; Payments owns collection.

## Credit Correction

`Issued Invoice -> Draft Credit Note -> Confirm -> accounting/tax correction -> optional Reverse`

## Procurement Requeue

A failed supplier PO preserves historical procurement demand and creates a new open requirement only for the outstanding quantity.
