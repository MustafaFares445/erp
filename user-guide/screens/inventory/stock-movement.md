---
title: "Stock Movements"
module: inventory
pages: ["index", "view"]
screenshots: ["inventory-stock-movement-index", "inventory-stock-movement-view"]
lastVerified: 2026-10-07
---

# Stock Movements

<IerpNavigationPath :items='["Inventory", "Stock", "Stock Movements"]' />

Trace how a product's physical quantity or condition changed in a warehouse. You need permission to view Inventory movements. This is a history screen; operators do not create or edit movement rows here.

## When to use this page

Use it to investigate a balance difference, verify a receipt or delivery, trace a transfer or return, and follow the source of a correction. Open **Inventory → Stock → Stock Movements**, or use **View Movements** from a stock detail page.

## Typical tasks

1. Search for the SKU. Use **Filter** to narrow the warehouse, product variant, movement type or date range. Condition, serialized-device and source-type filters are also available.
2. Compare the movement date, warehouse, type and **Base Quantity Delta**. A positive delta adds quantity; a negative delta removes it. Read the movement type for changes that affect condition or reservation rather than treating every row as a sale.
3. Open **View** to inspect the original quantity/unit, conversion factor, lot or serial when present, condition balances and notes.
4. Follow **Source** or **Reversal of** when a link is available. A plain reference still identifies provenance, but may have no directly accessible detail screen for your role.

## Working list

<IerpScreenshot id="inventory-stock-movement-index" imageSrc="/images/en/inventory/inventory-stock-movement-index.jpg" title="Stock movement history" description="Search physical history and inspect signed quantities and source references." path="Inventory → Stock → Stock Movements" />

The list shows the operator who created the movement, or System where no named operator is recorded. Optional columns provide the transaction unit/quantity, lot, serial, condition and reversal reference. Wide tables can scroll horizontally. **Export Inventory Movements** requests an Inventory export; download readiness follows that export workflow.

## Movement details

<IerpScreenshot id="inventory-stock-movement-view" imageSrc="/images/en/inventory/inventory-stock-movement-view.jpg" title="Movement quantities and provenance" description="Inspect a recorded movement without changing the original history." path="Inventory → Stock → Stock Movements → View" />

A dash means the fact was not recorded for this movement. Opening an older or untracked movement does not imply that it has a lot, serial or condition-transition snapshot. Use the source document to understand the business event.

## Stock, money and corrections

Viewing and filtering history changes no stock or financial balance. Inventory owns physical movements. Sales, Purchasing and Support may originate work, while Accounting owns ledger entries and Payments owns money movement. A physical movement is not proof that an invoice has been issued or a customer has paid.

Completed history is preserved. For an incorrect receipt, delivery, transfer, count or return, use its supported correction/reversal path and record the reason. Do not replace a historical row or create an unrelated movement to conceal a discrepancy.

## When something looks wrong

Check the warehouse, date range and SKU first. Compare transaction quantities in their recorded unit with the signed base quantity; a unit conversion can explain different numbers. Clear filters if the expected source is missing. If a source link is unavailable, ask an operator with access to the owning workflow to review the reference. Escalate an unexplained difference before posting a correction.

Related: [Stock Levels](/screens/inventory/stock-level), [Inventory Corrections](/screens/inventory/inventory-correction), [Inventory Operations](/screens/inventory/inventory-operation).
