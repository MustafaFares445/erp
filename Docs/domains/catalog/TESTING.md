# Catalog & Pricing Testing

---
status: canonical
owner: catalog
last_verified: 2026-10-07
verified_against: Inventory product/pricing/UOM/import tests and CRM pricing tests
---

Relevant coverage is currently distributed mainly through `tests/Feature/Inventory/`, `tests/Feature/Crm/` and `tests/Feature/Settings/`.

Important areas include manufacturers/brands, product tracking/profile rules, UOM and packaging conversion, catalog import, SKU/serial identity guards, media behavior, customer price lists and quantity breaks, pricing tiers, discount ceilings, price-floor overrides and quotation pricing.

A pricing change should run both catalog/pricing tests and Sales quotation/order pricing regression tests.
