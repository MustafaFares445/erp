# Data Model: Complete Inventory Reports and Exports

## inventory_exports

- Reuse existing `type`, `filters`, `status`, `file_path`, creator, failure reason, and completion timestamp.
- Increase `type` length only if required by the longest approved enum value.
- Cast `type` and `status` to typed enums where compatible with existing records.
- Filters remain JSON and contain only normalized scalar/list values supported by the selected report.

## Report Sources

No source schema changes are required.

- Catalog: product variants with product, brand, category, unit, and supplier references.
- Stock: inventory stocks with warehouse and variant pricing.
- Movements: immutable inventory movements and optional serialized device.
- Devices: serialized units with current warehouse, receipt, and movement count/timeline link.
- Expiry: inventory lots with warehouse, variant, available lot quantity, and expiry state.
- Suppliers: supplier product references with supplier, variant, country, purchase cost, and original currency.
- Pricing: price histories, tiers, customer-tier assignments, and floor overrides.
- Imports: import runs and their row outcomes/entity identifiers.

## Permissions

Every report requires `ReportView` and a source permission.

- Catalog and supplier identity: `CatalogView`.
- Stock, devices, and expiry: `StockView`.
- Movements: `MovementView`.
- Import runs and rows: `ImportManage`.
- Price history, tiers, assignments, overrides, supplier prices, cost, and valuation: `PricingView`.
- File request/download: `Export` in addition to all report permissions.
