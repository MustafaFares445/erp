# Contract: Inventory Report Exports

## Export Types

- catalog
- stock_levels
- movements
- devices
- expiry_lots
- supplier_comparison
- price_history
- pricing_tiers
- import_results

## Lifecycle

`Queued -> Processing -> Completed|Failed`

The existing export record stores normalized filters and creator. The existing queued job generates a unique private `.xlsx` file.

## Content

- Catalog, stock, movement, device, expiry, supplier, and price-history exports contain one header row followed by filtered source rows.
- Pricing-tier export contains sections for tiers, customer assignments, and floor overrides.
- Import-results export contains run context plus row status, errors, runtime error, and affected identifiers.
- Stock export includes on-hand, reserved, damaged, available, and optional pricing/value columns.
- Supplier prices always include original currency and are never converted or ranked across currencies.

## Authorization

- Request: `Export + ReportView + source permission`; add `PricingView` for pricing-sensitive types.
- Generation: recheck the creator before writing sensitive data.
- Download: recheck the downloader using the same matrix.
- Files have no public URL and are served only by the authorized download action.
