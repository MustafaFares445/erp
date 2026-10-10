---
title: "Inventory Counts / Cycle Counts"
module: inventory
pages: ["index", "create", "view"]
screenshots: ["inventory-inventory-count-index", "inventory-inventory-count-create", "inventory-inventory-count-view"]
lastVerified: 2026-10-07
---

# Inventory Counts and Cycle Counts

<IerpPageSummary purpose="Compare physically counted warehouse quantities with a frozen system snapshot before authorizing corrections." useWhen="Opening and reviewing scoped physical or cycle counts." owner="Inventory" />

## When to use this page

Opening and reviewing scoped physical or cycle counts.

## Typical tasks

- Follow the documented “Purpose and prerequisites” instructions below.
- Follow the documented “Open a count” instructions below.
- Follow the documented “Controls and exceptions” instructions below.

## Purpose and prerequisites

Use a physical count to compare what is present in a particular warehouse with the system snapshot. Count only selected warehouse conditions and the intended scope. Ensure the counter can inspect the goods and has access to the relevant warehouse. A count is not an immediate stock adjustment.

## Open a count

1. Open **Inventory → Operations → Inventory Counts / Cycle Counts**, then **Open Count**.
2. Choose the warehouse and scope: entire warehouse, category, lot or **Variant Set**. Variant Set requires one or more explicit variants; switching scopes changes the dependent fields.
3. Choose the stock conditions to count and review the variance review threshold. The opening action creates a counting document and snapshots system quantities.
4. On the count detail page, work through the **Count Lines**: record physical quantities, review variances and request recounts when needed. Mark variance decisions with reasons; do not assume a draft count changes stock.
5. Submit for review, then confirm or cancel using the actions available to your role. A confirmed count can generate a linked Inventory Adjustment for authorized corrections. Inspect the adjustment and subsequent stock movement as posting evidence.

## Controls and exceptions

The lifecycle is Draft → Counting → Pending Review → Confirmed (or Cancelled). A missing or invalid variant selection is rejected. Recounts and accepted variances must remain visible in the count lines and progress summary. Where the physical number differs, the stored inventory movement, not a changed count-row label, proves the stock correction occurred. Warehouse, permissions and status can hide subsequent actions.

See [Adjustments](/screens/inventory/adjustment), [Stock Movements](/screens/inventory/stock-movement) and [Inventory Operations](/screens/inventory/inventory-operation).

## Reviewed dashboard screens

<IerpScreenshot id="inventory-inventory-count-index" title="Count worksheet queue" description="Reviewed dashboard screen in the isolated demo environment." path="Inventory → Operations" imageSrc="/images/en/inventory/inventory-inventory-count-index.png" />

<IerpScreenshot id="inventory-inventory-count-create" imageSrc="/images/en/inventory/inventory-inventory-count-create.jpg" title="Select warehouse and count scope" description="Reviewed dashboard screen in the isolated demo environment." path="Inventory → Operations" />

<IerpScreenshot id="inventory-inventory-count-view" imageSrc="/images/en/inventory/inventory-inventory-count-view.jpg" title="Review count progress and variance" description="Reviewed dashboard screen in the isolated demo environment." path="Inventory → Operations" />

## Live View diagnostics

The View page includes read-only operational diagnostics: outstanding count lines/recounts/variances or blocked operations, with next steps. Workflow buttons and approvals remain authoritative. Diagnostics never post stock.
