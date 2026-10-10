# Catalog & Pricing Business Rules

---
status: canonical
owner: catalog
last_verified: 2026-10-07
verified_against: pricing/product/UOM/catalog-import services and tests
---

- Manufacturer and Brand are independent catalog facts and may both be attached to a product.
- Product variants are the transactional product identity used by Inventory, Sales and Purchasing.
- Explicit tracking/profile metadata is authoritative for modern lot/serial/expiry behavior while legacy tracking flags remain compatibility projections during migration.
- Units of measure may carry stable codes, families and precision metadata. UOM conversions are normalized through `QuantityNormalizer`; callers should persist transaction-unit/conversion/base-quantity snapshots where commercial/physical history must remain stable.
- Product-type guards enforce serial/batch/expiry/quantity rules appropriate to the variant type.
- Variant-UOM definitions may carry stable conversion, packaging name and barcode metadata; transactional documents preserve conversion snapshots where history must remain stable.
- `PriceResolver` is the runtime authority for resolved selling-price candidates; callers should not recreate pricing precedence independently.
- Customer price lists may be assigned directly, through customer group, or as the customer default. Product/variant prices, quantity breaks, validity/currency and deterministic precedence are resolved server-side, with price-list provenance retained downstream.
- Quantity-break evaluation uses normalized base quantity so equivalent piece/box/other UOM requests resolve consistently.
- Price-floor validation is explicit. Below-floor behavior requires the approved override path rather than a silent discount.
- Pricing-tier mutation/activation/linking uses `PricingTierService` and shared constraint enforcement.
- Product pricing changes are tracked through pricing/history services rather than direct UI arithmetic.
- Catalog import validates identities/product-type requirements before application.
- Product/variant media synchronization is centralized.
