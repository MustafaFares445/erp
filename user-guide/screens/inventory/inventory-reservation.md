---
title: "Reservations"
module: inventory
pages: ["index", "view"]
screenshots: ["inventory-inventory-reservation-index", "inventory-inventory-reservation-view"]
lastVerified: 2026-10-07
---

# Reservations

<IerpPageSummary purpose="Review allocated quantity, lot/serial source, expiry and release history." useWhen="Review, filter or act on the corresponding Inventory records according to stage and permission." owner="Inventory" />

## When to use this page

Review, filter or act on the corresponding Inventory records according to stage and permission.

## Typical tasks

- Follow the documented “Operator tasks” instructions below.
- Follow the documented “Business rules and troubleshooting” instructions below.
- Follow the documented “Screen references” instructions below.

## When to use
Review allocated quantity, lot/serial source, expiry and release history.

## Operator tasks
1. Open the Inventory navigation workspace and verify your current language, warehouse scope and selected record.
2. Use the list search, status filters or an appropriate detail tab to find the correct document.
3. Review quantities, units, source links, stage, ownership and permitted actions before editing.
4. Confirm the resulting record state and check stock movements after any separately authorized physical posting.

## Business rules and troubleshooting
Reservation reduces available but not on-hand. Only authorized lifecycle actions may release or consume it.

If the action is missing, check your role and the record lifecycle. Blank tables may reflect filters or missing demo records. A rendered route is not proof that a physical workflow has been completed.

## View: reservation lifecycle and release

The View panel summarizes whether the reservation is active, consumed, released or expired. Below it, review SKU, warehouse, base quantity, source document, lot/serial allocations, expiry and lifecycle history. Release is available only for Active records with the appropriate permission and a required reason; release restores availability but does not cancel the source order.

## Screen references

<IerpScreenshot id="inventory-inventory-reservation-index" title="Reservations — List page" description="Review allocated quantity, lot/serial source, expiry and release history." path="Inventory" />

<IerpScreenshot id="inventory-inventory-reservation-view" title="Reservations — Record details" description="Review allocated quantity, lot/serial source, expiry and release history." path="Inventory" />
