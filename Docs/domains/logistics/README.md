# Logistics & Shipments Domain

---
status: canonical
owner: logistics
last_verified: 2026-10-02
verified_against: app/Services/Logistics, app/Services/Shipments, shipment tests and Inventory/Sales fulfillment integration
---

## Purpose

Logistics owns outbound planning, dispatch, shipment/package execution and arrival confirmation around a released sales order.

Inventory remains the owner of stock mutation; Sales remains the owner of order commercial state.

## Main Code Anchors

- `OutboundAvailabilityService.php`
- `OutboundFulfillmentService.php`
- `OutboundDispatchService.php`
- `ShipmentService.php`
- `ShipmentArrivalConfirmationService.php`
- `ShipmentAttachmentSynchronizer.php`

## Main UI Surfaces

Outbound Fulfillments, Shipments, Shipment Attachments, Packages and Package Types.

Shipment statuses are Planned, In Transit, Arrived and Cancelled.

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
