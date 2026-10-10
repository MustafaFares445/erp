---
title: "Serialized Devices"
module: inventory
pages: ["index", "view"]
screenshots: ["inventory-serialized-inventory-unit-index", "inventory-serialized-inventory-unit-view"]
lastVerified: 2026-10-07
---

# Serialized Devices

<IerpPageSummary purpose="Review the unique serial identity and its current custody and movement history." useWhen="Review, filter or act on the corresponding Inventory records according to stage and permission." owner="Inventory" />

## When to use this page

Review, filter or act on the corresponding Inventory records according to stage and permission.

## Typical tasks

- Follow the documented “Operator tasks” instructions below.
- Follow the documented “Business rules and troubleshooting” instructions below.
- Follow the documented “Screen references” instructions below.

## When to use
Review the unique serial identity and its current custody and movement history.

## Operator tasks
1. Open the Inventory navigation workspace and verify your current language, warehouse scope and selected record.
2. Use the list search, status filters or an appropriate detail tab to find the correct document.
3. Review quantities, units, source links, stage, ownership and permitted actions before editing.
4. Confirm the resulting record state and check stock movements after any separately authorized physical posting.

## Business rules and troubleshooting
Serial quantities are atomic; never reuse a serial or infer customer ownership from a draft document.

If the action is missing, check your role and the record lifecycle. Blank tables may reflect filters or missing demo records. A rendered route is not proof that a physical workflow has been completed.

## Screen references

<IerpScreenshot id="inventory-serialized-inventory-unit-index" title="Serialized Devices — List page" description="Review the unique serial identity and its current custody and movement history." path="Inventory" />

<IerpScreenshot id="inventory-serialized-inventory-unit-view" title="Serialized Devices — Record details" description="Review the unique serial identity and its current custody and movement history." path="Inventory" />
