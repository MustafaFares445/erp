# Contract: Inventory Report Queries

## Report Keys

- catalog
- stock_levels
- movements
- devices
- expiry_lots
- supplier_comparison
- price_history
- pricing_tiers
- customer_assignments
- floor_overrides
- import_runs
- import_results

## Shared Rules

- Return an Eloquent builder for the report source.
- Normalize filters before applying them.
- Ignore unsupported filter keys instead of passing them to SQL.
- Treat blank strings as absent.
- Reject an invalid date range.
- Use deterministic primary-key ordering for exports.
- Never include soft-deleted records unless the existing source report intentionally does so.

## Supported Filters

- Catalog: product, category, brand, status.
- Stock: warehouse, product variant, availability state.
- Movements: warehouse, variant, movement type, from, until.
- Devices: warehouse, variant, status, identity search.
- Expiry lots: warehouse, variant, expiry state, from, until.
- Supplier comparison: supplier, variant, country, currency.
- Price history: variant, changed by, from, until.
- Pricing tiers/assignments/overrides: active, customer, variant, from, until as applicable.
- Imports: run, status, created by, from, until.
