# Catalog & Pricing Domain

---
status: canonical
owner: catalog
last_verified: 2026-10-02
verified_against: product/pricing services currently under app/Services/Inventory, product/pricing Filament resources and Inventory/CRM tests
---

## Purpose

Catalog & Pricing owns sellable product/variant definitions, media, UOM conversion definitions, pricing tiers, price history, price-floor approval and catalog import behavior.

Several current services physically live under `app/Services/Inventory`; this documentation boundary is business-oriented and does not require an immediate code-folder refactor.

## Main Code Anchors

- `PriceResolver.php`
- `PricingTierService.php`
- `PricingTierDiscountCalculator.php`
- `ProductPricingService.php`
- `ProductVariantUomService.php`
- `ProductTypeGuard.php`
- `QuantityNormalizer.php`
- catalog import/media synchronizers

## Main UI Surfaces

Products, Product Variants, Pricing Tiers, Price Histories, Price Floor Overrides and catalog import-related screens.

## Consumers

Sales, Purchasing, Inventory, CRM, Support and future mobile apps consume catalog/pricing facts.

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
