# Inventory Business Rules

---
status: canonical
owner: inventory
last_verified: 2026-10-02
verified_against: InventoryOperationService, InventoryPostingService, InventoryBalanceService, reservation/count/return/correction services and tests
---

## Stock Balance Invariant

For each variant/warehouse balance:

- on-hand, reserved, and damaged quantities cannot be negative.
- reserved + damaged cannot exceed on-hand.
- available quantity is derived as `on_hand - reserved - damaged`.
- exact base-UOM arithmetic is normalized to six decimal places where required.

## Canonical Physical Operations

`InventoryOperation` supports Receipt, Delivery, and Internal Transfer.

Stages are `Draft`, `Waiting`, `Ready`, `InTransit`, `PartiallyReceived`, `Done`, and `Canceled`.

On-hand changes when warehouse custody changes, not when a document is merely drafted or marked ready.

- `markReady()` validates/snapshots lines and reserves outbound stock where applicable.
- A Receipt reaching Ready does not itself increase stock.
- A Delivery loses source custody when completed.
- An Internal Transfer loses source custody at dispatch and gains destination custody only as quantities are actually received.
- Transfer receipt may be partial and discrepancy handling is explicit.
- Cancelling an already-dispatched transfer compensates the source so cancellation does not leave the source on-hand reduced.

## Reservations

Reservations protect outbound availability without changing on-hand. Consumption/release is coordinated through `InventoryReservationService`.

## Posting

`InventoryPostingService` is the canonical posting path for inventory balance deltas and movements. Legacy direct balance helpers remain only for compatibility while callers converge.

A stock-changing action must preserve both balance correctness and movement/audit provenance.

## Product Type / UOM / Tracking

- transaction quantities are normalized into base UOM;
- serialized lines require valid serial coverage and atomic serialized handling;
- batch/lot-tracked products use inventory lots;
- expiry requirements are enforced when the product type requires them;
- duplicate SKU/serial/IoT identities are guarded.

## Counts

Count statuses are `Draft -> Counting -> Pending Review -> Confirmed`, or `Cancelled`.

Counts snapshot system quantities by the applicable granularity, record counted quantities, and require explicit variance/review behavior before confirmation.

## Returns, Corrections and Condition Changes

Posted physical history is corrected through explicit Inventory documents/services rather than rewriting completed movement history.

Customer/supplier returns, receipt/delivery/transfer corrections, quarantine/damage/recovery/disposal and count corrections use their dedicated workflows.

## Authorization

Inventory permissions are granular across warehouse/stock/movement view, receipt/delivery/transfer actions, adjustment/count/return/correction/condition-change actions, pricing/catalog, import/report/export, alerts and expired-stock override.

Service/policy authorization remains authoritative; UI visibility alone is not a security boundary.
