# Catalog & Pricing Domain

---
status: canonical
owner: catalog
last_verified: 2026-10-07
verified_against: product/pricing services currently under app/Services/Inventory, product/pricing Filament resources and Inventory/CRM tests
---

## Purpose

Catalog & Pricing owns sellable product/variant definitions, manufacturers/brands, media, tracking/profile metadata, UOM and packaging conversions, customer price lists/quantity breaks, pricing tiers, price history, price-floor approval and catalog import behavior.

Several current services physically live under `app/Services/Inventory`; this documentation boundary is business-oriented and does not require an immediate code-folder refactor.

## Main Code Anchors

- `PriceResolver.php`
- `PricingTierService.php`
- `PricingTierDiscountCalculator.php`
- `ProductPricingService.php`
- `ProductVariantUomService.php`
- `ProductTypeGuard.php`
- `QuantityNormalizer.php`
- Product/Variant UOM and packaging models/services
- Manufacturer, Brand and Price List models/resources
- catalog import/media synchronizers

## Main UI Surfaces

Products, Product Variants, Manufacturers, Brands, Price Lists, Pricing Tiers, Price Histories, Price Floor Overrides and catalog import-related screens.

## Consumers

Sales, Purchasing, Inventory, CRM, Support and future mobile apps consume catalog/pricing facts.

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
