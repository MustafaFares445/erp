# Catalog & Pricing Business Rules

---
status: canonical
owner: catalog
last_verified: 2026-10-02
verified_against: pricing/product/UOM/catalog-import services and tests
---

- Product variants are the transactional product identity used by Inventory, Sales and Purchasing.
- UOM conversions are normalized through `QuantityNormalizer`; callers should persist transaction-unit/conversion/base-quantity snapshots where commercial/physical history must remain stable.
- Product-type guards enforce serial/batch/expiry/quantity rules appropriate to the variant type.
- `PriceResolver` is the runtime authority for resolved selling-price candidates; callers should not recreate pricing precedence independently.
- Price-floor validation is explicit. Below-floor behavior requires the approved override path rather than a silent discount.
- Pricing-tier mutation/activation/linking uses `PricingTierService` and shared constraint enforcement.
- Product pricing changes are tracked through pricing/history services rather than direct UI arithmetic.
- Catalog import validates identities/product-type requirements before application.
- Product/variant media synchronization is centralized.
