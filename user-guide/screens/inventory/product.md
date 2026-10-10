---
title: "Products"
module: inventory
pages: ["index", "view", "edit", "variants", "vendors", "quantities", "movements"]
screenshots: ["inventory-product-index", "inventory-product-view", "inventory-product-edit", "inventory-product-variants", "inventory-product-vendors", "inventory-product-quantities", "inventory-product-movements"]
lastVerified: 2026-10-07
---

# Products

<IerpNavigationPath :items='["Inventory", "Stock", "Products"]' />

A product groups one or more variants (SKUs). Stock is always held per variant and warehouse. The product definition (names, category, tracking, pricing) is owned by the catalog; this page adds a read-only stock picture for Inventory users. You need permission to view products or the catalog.

## When to use this page

Use it to answer "do we have this product, where, and can we use it?" before promising it, and to find which variant or warehouse needs attention. To change quantities use the Inventory workflows (receipts, transfers, counts, adjustments); editing the product never changes stock.

## Typical tasks

1. Open **Inventory → Stock → Products** and search by name, SKU, manufacturer or brand.
2. Open **View**. Read **Operational health** first: it checks the variants and the stock of all variants across all warehouses.
3. Check **Key figures**: **Variants** (with how many are active), **Warehouses with stock**, **On Hand**, **Available**, **Reserved** and **Restricted stock** (quarantined or damaged).
4. Review **Product identity** for the catalog data, then **Variants, tracking and stock** for each SKU's tracking requirement (none, lot/batch or serial), whether an expiry date is required, its status and its quantities.
5. Use the record tabs to open variants, vendors, quantities per warehouse and stock movements.

## Operational health

| You may see | What it means | What to do |
| --- | --- | --- |
| **The product has no variants** | Without a variant (SKU) the product cannot be received, counted or delivered. | Ask the catalog owner to add a variant. |
| **… variant(s) are inactive** | Inactive variants cannot be used in new inventory documents; their stock and history stay visible. | Review the variants with the catalog owner. |
| **No stock recorded in any warehouse** | No variant has on-hand stock. | Check open receipts if stock is expected. |
| **Stock is on hand but none is available** | All on-hand stock is reserved, quarantined or damaged. | Open the quantities per warehouse, then the stock level, to see which documents hold it. |
| **… held in quarantine or damaged** | Stock is present but excluded from availability until a condition decision is recorded. | Open the stock level and record the decision there. |
| **… warehouse balance(s) at or below the minimum** | Available stock reached the replenishment minimum. | Review the quantities per warehouse and raise replenishment. |
| **… expired lot(s) still hold saleable stock** | Expired lots inflate availability and cannot be picked without the expired-stock override. | Open the lot and remove expired stock from saleable through a condition change. |

Quantities on this page add up all warehouses and may mix units if variants use different units. For decisions, use the stock level of one SKU in one warehouse.

## Screen references

<IerpScreenshot id="inventory-product-index" title="Products list" description="Search products by name, SKU, manufacturer or brand." path="Inventory → Stock → Products" />

<IerpScreenshot id="inventory-product-view" title="Product stock health and variants" description="Operational health, key figures, identity and the per-variant tracking and stock summary." path="Inventory → Stock → Products → View" />

<IerpScreenshot id="inventory-product-edit" title="Edit a product" description="Catalog data of the product. Editing never changes stock." path="Inventory → Stock → Products → Edit" />

<IerpScreenshot id="inventory-product-variants" title="Product variants" description="The SKUs of the product with their units and tracking settings." path="Inventory → Stock → Products → Variants" />

<IerpScreenshot id="inventory-product-vendors" title="Product vendors" description="Suppliers and their references for this product." path="Inventory → Stock → Products → Vendors" />

<IerpScreenshot id="inventory-product-quantities" title="Quantities per warehouse" description="Balances of each variant in each warehouse, including stock in transit." path="Inventory → Stock → Products → Quantities" />

<IerpScreenshot id="inventory-product-movements" title="Product stock movements" description="Posted stock movements of all variants of the product." path="Inventory → Stock → Products → Movements" />

## When something looks wrong

- A variant shows "Expiry date required" but lots have no expiry: open the lot; its health panel reports the missing expiry date.
- Totals differ from a warehouse report: totals include every warehouse; filter the quantities tab by warehouse.

Related: [Stock Levels](/screens/inventory/stock-level), [Inventory Lots](/screens/inventory/inventory-lot), [Product Variants](/screens/inventory/product-variant).
