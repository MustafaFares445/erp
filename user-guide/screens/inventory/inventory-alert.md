---
title: "Planning & Alerts"
module: inventory
pages: ["index", "view"]
screenshots: ["inventory-inventory-alert-index", "inventory-inventory-alert-view"]
lastVerified: 2026-10-07
---

# Planning & Alerts

<IerpPageSummary purpose="Inspect low-stock, expiry and operational alerts as review queues." useWhen="Review, filter or act on the corresponding Inventory records according to stage and permission." owner="Inventory" />

## When to use this page

Review, filter or act on the corresponding Inventory records according to stage and permission.

## Typical tasks

- Follow the documented “Operator tasks” instructions below.
- Follow the documented “Business rules and troubleshooting” instructions below.
- Follow the documented “Screen references” instructions below.

## When to use
Inspect low-stock, expiry and operational alerts as review queues.

## Operator tasks
1. Open the Inventory navigation workspace and verify your current language, warehouse scope and selected record.
2. Use the list search, status filters or an appropriate detail tab to find the correct document.
3. Review quantities, units, source links, stage, ownership and permitted actions before editing.
4. Confirm the resulting record state and check stock movements after any separately authorized physical posting.

## Business rules and troubleshooting
Alerts are not physical movements; review stock facts and create explicit replenishment or condition actions.

If the action is missing, check your role and the record lifecycle. Blank tables may reflect filters or missing demo records. A rendered route is not proof that a physical workflow has been completed.

## View: alert diagnosis and source

The View screen shows the Operational health and next steps panel, followed by alert type, severity, context, source reference, recommendation and resolved status. The diagnosis is read-only: investigate the linked operational record; dismissing the warning panel does not change inventory or mark the underlying alert resolved.

## Screen references

<IerpScreenshot id="inventory-inventory-alert-index" title="Planning & Alerts — List page" description="Inspect low-stock, expiry and operational alerts as review queues." path="Inventory" />

<IerpScreenshot id="inventory-inventory-alert-view" title="Planning & Alerts — Record details" description="Inspect low-stock, expiry and operational alerts as review queues." path="Inventory" />
