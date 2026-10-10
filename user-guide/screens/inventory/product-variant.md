---
title: "Product Variant"
module: inventory
pages: ["index", "view", "attributes"]
screenshots: ["inventory-product-variant-index", "inventory-product-variant-view", "inventory-product-variant-attributes"]
lastVerified: 2026-10-07
---

# Product variants

<IerpPageSummary purpose="Review individual SKUs, units of measure, tracking identity and variant attributes while preserving historical stock integrity." useWhen="Inspect a product's variants, their tracking and attributes, or navigate from an older variant link." owner="Inventory" />

## When to use this page

Inspect a product's variants, their tracking and attributes, or navigate from an older variant link.

## Typical tasks

- Follow the documented “Locate the variant” instructions below.
- Follow the documented “Legacy View link” instructions below.
- Follow the documented “Permissions and validation” instructions below.

## Locate the variant

1. Open **Inventory → Products**, select the product and open its **Product Variants** tab. The list contains variants for that product.
2. Use the variant's SKU, base unit, tracking mode and attributes to distinguish the stock identity from the product master.
3. Inspect **Attributes** for the variant's assigned values. Changes to labels or product options do not themselves receive, reserve or transfer stock.
4. When available, use a permitted Edit action to change appropriate draft/reference fields. Once stock history exists, the base units and tracking identity have restrictions to protect historic records.

## Legacy View link

The older **Product Variant → View** URL intentionally opens the **parent product's Product Variants tab**, not an independent variant detail page. This is the canonical navigation by design. The screenshot below shows that destination, not a second View interface. Use the SKU and linked product to locate the intended variant.

## Permissions and validation

If an action is missing, review the variant's status, stock history and your permission. Product and variant identifiers must remain unique under the applicable tracking policy. Verify lot/serial and warehouse availability in their dedicated screens; changes to reference data do not rewrite posted movements.

## Screens

<IerpScreenshot id="inventory-product-variant-index" title="Product variants list" description="Browse stock identities and their units, status and tracking mode." path="Inventory → Products → Product Variants" />

<IerpScreenshot id="inventory-product-variant-view" title="Canonical destination of Variant View" description="The legacy variant View link redirects to its parent product's Variants tab." path="Product Variant → View → Parent Product Variants" />

<IerpScreenshot id="inventory-product-variant-attributes" title="Variant attributes" description="Review the assigned attribute values and their relationships." path="Inventory → Product Variant → Attributes" />
