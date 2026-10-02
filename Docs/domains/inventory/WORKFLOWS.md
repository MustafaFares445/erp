# Inventory Workflows

---
status: canonical
owner: inventory
last_verified: 2026-10-02
verified_against: InventoryOperationService and related Inventory services
---

## Receipt

1. Create a receipt with destination warehouse and lines.
2. Ready validates lines and snapshots UOM/product facts.
3. No stock is added at Ready.
4. Completion receives through Inventory posting/lot services.
5. The operation becomes Done and emits `InventoryOperationCompleted`.

Purchasing can react to completed receipts to advance PO/inbound received quantities; Inventory stays independent of Purchasing.

## Delivery

1. Prepare delivery lines against a source warehouse.
2. Ready validates availability and reserves outbound quantities.
3. Completion consumes reservation/material and removes source custody.
4. The operation becomes Done.

Draft/Waiting/Ready do not reduce on-hand.

## Internal Transfer

1. Draft/Waiting -> Ready after validation/reservation.
2. Dispatch removes source custody and moves to In Transit.
3. Destination receives physically counted quantity.
4. Partial receipt moves to Partially Received.
5. Unreceived quantity requires explicit discrepancy disposition/reason.
6. When all lines are settled, destination custody is posted and the transfer becomes Done.

## Cancellation

Cancellation releases outstanding reservations. When a transfer was already dispatched, cancellation restores source custody with compensating inventory history.

## Physical Count

Open -> record counts/recounts/variance decisions -> submit for review -> confirm or cancel.

Confirmation uses explicit correction/posting behavior rather than silently editing movement history.

## Returns / Corrections

- Customer and supplier returns are distinct.
- Inspection/disposition precedes posting where required.
- Posted receipt/delivery/transfer errors use correction documents.
- Condition changes use draft/post/cancel workflow.

## Cross-Domain Entry Points

Purchasing creates receipt provenance/allocations, Sales/Logistics create fulfillment demand, and Support may consume/recover parts. Inventory alone owns physical stock mutation.
