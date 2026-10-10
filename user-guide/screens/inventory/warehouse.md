---
title: "Warehouses"
module: inventory
pages: ["index", "create", "view", "edit"]
screenshots: ["inventory-warehouse-index", "inventory-warehouse-create", "inventory-warehouse-view", "inventory-warehouse-edit"]
lastVerified: 2026-10-07
---

# Warehouses

<IerpNavigationPath :items='["Inventory", "Setup", "Warehouses"]' />

A warehouse is a stock location: every balance, receipt, delivery, transfer and count belongs to one. This page keeps the warehouse identity and shows what currently needs attention in it. You need permission to view warehouses; creating or editing one needs warehouse management permission.

## When to use this page

Use it to set up a new location, deactivate one that is no longer used, or check the state of a warehouse before planning work there: which items cannot be used, which documents are stuck and which counts wait for review. Creating or editing a warehouse never moves stock; receipts, deliveries and transfers do.

## Typical tasks

1. Open **Inventory → Setup → Warehouses** and search by code or name.
2. Open **View**. Read **Operational health** first. Each item names the problem, how many records are affected (with up to five example SKUs) and a **Next step** link.
3. Check **Key figures**: **SKUs in Stock**, **On Hand**, **Available** (with **Reserved** underneath), **Open Receipts**, **Ready to Dispatch** and **Transfers In Transit**. Totals add quantities across different items, so treat them as a workload indicator, not a stock value.
4. Use **View Stock**, **View Movements** or **View Reservations** in the page header to open the lists already filtered to this warehouse.
5. Use **Edit** to correct the code, name, address, coordinates or active status.

## Operational health

The checks are read-only and scoped to this warehouse. When only one item balance is affected, the next step opens that stock level directly; otherwise it opens the filtered stock list.

| You may see | What it means | What to do |
| --- | --- | --- |
| **Warehouse is inactive** (Blocker) | The warehouse cannot be used for new receipts, deliveries, transfers or adjustments. | **Edit the warehouse** and activate it if it should be used again. |
| **Location coordinates are missing** | Location-based delivery planning cannot use this warehouse. | **Edit the warehouse** and enter latitude and longitude. |
| **… balance(s) have more reserved than on hand** (Blocker) | Stored balances contradict each other. | Open the stock levels listed and report them to an inventory supervisor before posting more movements. |
| **… item(s) have stock on hand but none available** | All units of these items are reserved, quarantined or damaged. | Open the stock level; its own health panel names the documents that hold the stock. |
| **… item(s) are at or below their replenishment minimum** | Available stock reached the configured minimum. | Review the low-stock list and raise replenishment. |
| **… item(s) have stock waiting in quarantine** | Quarantined stock waits for a decision. | Record a quarantine decision from the stock level. |
| **… expired lot(s) still hold saleable stock here** | Expired lots inflate availability and cannot be picked without the expired-stock override. | Open the stock list and remove expired stock from saleable through a condition change. |
| **… open stock alert(s)** | Alerts on balances of this warehouse are not resolved. | **Review open alerts**. |
| **… delivery(ies) waiting for stock** | Deliveries from this warehouse could not be prepared. | **Review waiting deliveries**; each delivery's own panel shows which SKU is short. |
| **… transfer(s) on the way to this warehouse** | Goods left the source but receipt here is not recorded. | **Review incoming transfers** and record the receipt when the goods arrive. |
| **… count(s) waiting for review** | Counted quantities are not confirmed, so differences are not posted. | A user other than the counter reviews and confirms the count. |
| **No stock recorded in this warehouse** | Nothing has been received yet, or everything was issued. | No action unless you expected stock here. |

**No problem found** means only that the listed checks found nothing. It is not a physical audit.

## Screen references

<IerpScreenshot id="inventory-warehouse-index" title="Warehouses list" description="Search warehouses by code or name and open one to review it." path="Inventory → Setup → Warehouses" />

<IerpScreenshot id="inventory-warehouse-create" title="Create a warehouse" description="Enter the code, name, address, coordinates and active status." path="Inventory → Setup → Warehouses → New" />

<IerpScreenshot id="inventory-warehouse-view" title="Warehouse operational health and key figures" description="Operational health, key figures and identity of one warehouse." path="Inventory → Setup → Warehouses → View" />

<IerpScreenshot id="inventory-warehouse-edit" title="Edit a warehouse" description="Correct identity, coordinates or active status. Editing never moves stock." path="Inventory → Setup → Warehouses → Edit" />

## When something looks wrong

- An action is missing: check your role. **View Reservations** appears only to users who may view reservations.
- A figure looks too high: totals add different items and units together; open the stock list for item-level quantities.
- A problem appears that you believe is fixed: the panel reads current data each time the page opens. Reload the page; if it persists, open the linked records.

Related: [Stock Levels](/screens/inventory/stock-level), [Stock Movements](/screens/inventory/stock-movement), [Inventory Counts](/screens/inventory/inventory-count).
