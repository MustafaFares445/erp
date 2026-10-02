# Sales Business Rules

---
status: canonical
owner: sales
last_verified: 2026-10-02
verified_against: SalesOrderService, QuotationConversionService, OrderCompletionService, procurement/invoice/credit services and tests
---

## Quotation

Statuses include Draft, Sent, Accepted, Rejected, Changes Requested, Expired, Converted to Delivery and Cancelled.

A quotation has no stock effect.

Only an Accepted, non-support-origin quotation can be converted. Conversion is locked and one-time, creating a Confirmed sales order with frozen commercial/UOM snapshots.

## Sales Order

Order statuses are `Draft -> Confirmed -> Released -> Closed`, with `Cancelled` as a terminal alternative.

- Draft is editable.
- Confirm requires active customer, at least one line, and frozen UOM/quantity/price/tax data.
- Release requires Confirmed and emits `SalesOrderReleased`.
- Cancellation is blocked when non-cancelled delivery execution exists.
- Short-close is available only on Released orders, requires a reason, and applies only to remaining unplanned demand.

## Procurement Requirements

Released orders synchronize shortages against current available inventory. Uncovered demand becomes `SalesProcurementRequirement`.

Purchasing attaches PO/line provenance. Completed receipt quantities fulfill demand. Cancelled/short-closed supply attempts are preserved as superseded history and only outstanding quantity is requeued.

## Order Completion

Only customer confirmation or scheduler auto-close sets Closed through `OrderCompletionService`.

Customer close requires ownership, Released status, eligibility and evidence. Evidence types are JPEG/PNG/WEBP/PDF with a 15 MB per-file limit.

The completion window starts only after fulfillment is complete, all shipments arrived, and no open procurement remains.

## Invoices

Invoice document statuses include Draft, Issued, Sent, Written Off and Cancelled. Financial state is derived separately as Not Payable Yet, Unpaid, Partially Paid, Paid, Credited or Overdue.

`InvoiceService` owns creation/issue/send. Accounting effects go through dedicated posting services.

## Credit Notes

Credit notes use Draft, Confirmed, Reversed and Cancelled states.

A credit linked to an invoice cannot exceed remaining uncredited invoice-line value or quantity. Confirm/reversal financial effects use dedicated posting services.

## Boundary Rules

- quotation never reserves/decrements stock;
- Inventory owns physical delivery stock effects;
- Payments owns collection/allocation/deposit behavior;
- Accounting owns ledger truth.
