---
title: "Adjustments"
module: inventory
pages: ["index", "create", "view", "edit"]
screenshots: ["inventory-adjustment-index", "inventory-adjustment-create", "inventory-adjustment-view", "inventory-adjustment-edit"]
lastVerified: 2026-10-07
---

# Inventory Adjustments

<IerpPageSummary purpose="Authorize auditable physical stock quantity adjustments with signed movement history." useWhen="Preparing a Draft, editing its lines and confirming quantities." owner="Inventory" />

## When to use this page

Preparing a Draft, editing its lines and confirming quantities.

## Typical tasks

- Follow the documented “Prepare and review” instructions below.
- Follow the documented “Corrections and boundaries” instructions below.
- Follow the documented “Reviewed dashboard screens” instructions below.

## When to use this workflow

An adjustment documents a controlled correction to the quantity of a specific product variant, stock condition, lot or serial in a warehouse. Use it for justified physical differences, including approved count variances. **Creating and editing a Draft does not post stock.**

## Prepare and review

1. Open **Inventory → Operations → Adjustments**, then **New inventory adjustment**.
2. Select the warehouse and a reason category. Enter an auditable explanation.
3. Add line items with the variant, stock condition and required lot or serial identity. Record the counted/new quantity. The form shows the current quantity and calculated difference.
4. Save the Draft and inspect its lines. Only permitted Draft changes can be edited; a confirmed adjustment is not an editable ledger substitute.
5. When authorized, review the posting action and confirm. Verify the final status, stock quantity and linked signed inventory movements. A difference that would violate available stock or tracked-unit constraints must be rejected.

## Corrections and boundaries

Use the explicit **Create Reversal Adjustment** action when appropriate rather than silently editing confirmed history. A count-generated adjustment should remain traceable to the count. Adjustments affect physical Inventory stock; they do not themselves issue supplier bills, customer credit notes or refunds. Search and filter the list by reference, warehouse, status and reason to reconcile activity.

Related: [Counts](/screens/inventory/inventory-count), [Stock Levels](/screens/inventory/stock-level), [Stock Movements](/screens/inventory/stock-movement).

## Reviewed dashboard screens

<IerpScreenshot id="inventory-adjustment-index" imageSrc="/images/en/inventory/inventory-adjustment-index.jpg" title="Inventory adjustment queue" description="Reviewed dashboard screen in the isolated demo environment." path="Inventory → Operations" />

<IerpScreenshot id="inventory-adjustment-create" imageSrc="/images/en/inventory/inventory-adjustment-create.jpg" title="Create a draft inventory adjustment" description="Reviewed dashboard screen in the isolated demo environment." path="Inventory → Operations" />

<IerpScreenshot id="inventory-adjustment-edit" imageSrc="/images/en/inventory/inventory-adjustment-edit.jpg" title="Edit a draft and its lines" description="Reviewed dashboard screen in the isolated demo environment." path="Inventory → Operations" />

<IerpScreenshot id="inventory-adjustment-view" imageSrc="/images/en/inventory/inventory-adjustment-view.jpg" title="Review confirmed adjustment evidence" description="Reviewed dashboard screen in the isolated demo environment." path="Inventory → Operations" />

## View: adjustment approval and diagnostics

The View screen displays Operational health and next steps before the existing adjustment details. Review warehouse, product variants, old/new quantities and differences. A Draft does not change stock. Confirm posts inventory movements and requires a different authorized user than the creator; a posted mistake requires a linked correction instead of editing the original.
