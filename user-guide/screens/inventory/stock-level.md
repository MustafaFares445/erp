---
title: "Stock"
module: inventory
pages: ["index", "view"]
screenshots: ["inventory-stock-level-index", "inventory-stock-level-view"]
lastVerified: 2026-10-07
---

# Stock Levels

<IerpNavigationPath :items='["Inventory", "Stock", "Stock Levels"]' />

Check quantities for a product variant in a particular warehouse before promising delivery or planning replenishment. You need permission to view stock. Changing stock requires access to the relevant Inventory workflow.

## When to use this page

Use this page to find available stock, investigate a reservation or shortage, and compare saleable, quarantined and damaged quantities. The list and detail page display balances; they do not let you overwrite quantities.

## Typical tasks

1. Open **Inventory → Stock**, then search for the SKU or variant. Check the warehouse as well as the product.
2. Use **Low Stock**, **Reservations** or **Out of stock** to focus the working list. Low Stock uses the warehouse replenishment policy; an item without an active minimum is never flagged as low.
3. Open **View**. Read **Operational health** first: it says whether anything blocks or limits this balance, why, and the next step.
4. Check **Key figures** for on-hand, available, reserved, quarantine, damaged and in-transit quantities, then **Replenishment** for the minimum, target and shortage.
5. Open **Availability Breakdown** (bottom of the page, or the row action in the list) to follow the exact reservation, receipt or condition change that holds stock. Use **View Movements** to trace physical changes and **Replenishment Policy** to review planning settings.

## Reading the quantities

| Quantity | Meaning |
| --- | --- |
| On Hand | Stock currently held in this warehouse. |
| Reserved | Stock committed to outstanding work; reservation alone does not remove stock. |
| Available | Saleable quantity remaining after its reservations. Use the breakdown and tracked-stock context before allocating. |
| In Transit | Transfer quantities traveling to this warehouse; they are separate from received on-hand stock. |
| Saleable / Quarantine / Damaged | Condition-specific balances shown on the detail page. Quarantined or damaged stock is not ordinary saleable stock. |
| Shortage to Target | The positive gap between the policy maximum and saleable available quantity. A dash means no target is configured. |
| Replenishment status | **Low Stock** when available is at or below the active minimum, **Above minimum** otherwise. **No minimum set** or **Minimum inactive** means low stock cannot be detected for this item here. |

## Operational health

The panel at the top of the detail page runs read-only checks on the recorded balances. Each item shows a severity, what was found, the supporting figures and a **Next step**. If you do not have the permission for that step, the link is replaced by a note asking a permitted user to do it. Open **What was checked** at the bottom of the panel to see which checks ran and what they cannot know.

| You may see | What it means | What to do |
| --- | --- | --- |
| **Recorded balances contradict each other** (Blocker) | Reserved is higher than saleable on-hand, or on-hand is negative. Normal posting never produces this. | Do not post further movements for the item. Review the movement history and report it to an inventory supervisor. |
| **Stock is on hand but none is available** | Every unit is reserved, quarantined or damaged. The quantities of each cause are listed. | Follow the cause items below it. |
| **… reserved for other documents** | Active reservations hold this quantity. Each reservation and its source document are listed. | Open the reservations. Release one only through the reservation's own **Release** action, if it is no longer needed. |
| **… held in quarantine** | Stock was received or moved into quarantine and waits for a decision. | **Record a quarantine decision** (release, downgrade, dispose or return to supplier) as a condition change, or finish the open draft decision. |
| **… recorded as damaged** | Damaged stock is excluded from availability. | Recover it if it is usable again, or dispose of it through the disposal workflow. |
| **Expired lots still counted as saleable** | Lots past their expiry still sit in saleable stock. Picking them is blocked without the expired-stock override. | Open the lot and record a condition change to remove the expired stock from saleable. |
| **Reservations past their expiry still hold stock** | The expiry time passed but the reservation is still active (the scheduled expiry job may not have run). | Open the reservation and check it with its owner. |
| **Available stock is at or below the minimum** | Available reached the replenishment minimum and incoming transfers do not cover it. | Review the replenishment policy and raise replenishment. |
| **Below the minimum, but incoming transfers cover it** | Stock on the way will bring the balance back above the minimum. | Receive the transfer when it arrives; no new replenishment is needed yet. |
| **Deliveries are waiting for this item** | Deliveries from this warehouse could not be prepared for lack of available stock. Waiting demand and available quantity are shown. | Open the delivery and use **Mark Ready** again once stock is available. |
| **No replenishment threshold is set / is inactive** | Low stock cannot be detected for this item in this warehouse. | Ask the planner to add or activate a policy if the item should be replenished. |

**No problem found** only means none of the listed checks found an issue. It does not prove the goods are on the shelf; only a physical count can.

Wide tables scroll horizontally. Use **Column manager** for optional columns and scroll to reach row actions.

## Working list

<IerpScreenshot id="inventory-stock-level-index" imageSrc="/images/en/inventory/inventory-stock-level-index.jpg" title="Stock Levels working list" description="Find a SKU and warehouse, then inspect balances and availability." path="Inventory → Stock" />

Search, sort and filter the list before opening a record. The export action requests an Inventory export; do not assume it behaves like the immediate Sales CSV download.

## Stock details

<IerpScreenshot id="inventory-stock-level-view" imageSrc="/images/en/inventory/inventory-stock-level-view.jpg" title="Stock identity, quantities and replenishment" description="Review current condition balances and the availability explanation." path="Inventory → Stock → View" />

## Changes and corrections

Viewing balances, searching and opening the breakdown change no stock or financial balance. Receipts, deliveries, transfers, returns, counts, adjustments and condition changes have their own Inventory workflows. The visible condition actions lead to those workflows; opening an action is not evidence that stock has been posted.

Keep completed movement history intact. Correct a physical mistake through the appropriate return or correction workflow. Sales owns commercial documents, Payments owns collections and Accounting owns financial posting.

## When something looks wrong

Confirm the SKU, warehouse and unit before comparing quantities. An availability gap can reflect reserved work or a stock condition; inspect the breakdown instead of subtracting reservations again. Compare movements with the actual source document. Missing thresholds require review of the replenishment policy. Ask the warehouse operator to investigate unexplained differences; do not edit historical movements to force a match.

Related: [Stock Movements](/screens/inventory/stock-movement), [Inventory Operations](/screens/inventory/inventory-operation), [Physical Count](/workflows/physical-count).
