# Logistics Workflows

---
status: canonical
owner: logistics
last_verified: 2026-10-02
verified_against: Logistics/Shipment services
---

## Fulfillment Planning

`Released Sales Order -> availability suggestion -> fulfillment plan -> Inventory delivery operation(s)`

Logistics decides how to plan/dispatch fulfillment, but uses Inventory operations for physical stock effects.

## Preparation / Dispatch

`Planned fulfillment -> prepare Inventory delivery -> dispatch -> Shipment In Transit`

`OutboundDispatchService` delegates inventory operation transitions to `InventoryOperationService` rather than changing stock directly.

## Arrival

Arrival may be confirmed by customer, admin, or system through the shipment confirmation services.

`Shipment In Transit -> Arrived`

Shipment completion integrates with downstream warranty/order-completion behavior. Order completion remains Sales-owned.

## Cancellation

Cancelled shipments do not count as arrived fulfillment and must remain consistent with the related delivery operation/order state.
