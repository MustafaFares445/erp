# Research: Complete Inventory Reports and Exports

## Decisions

### Keep Eloquent builders as the shared query boundary

`InventoryReportService` returns a source-specific Eloquent builder after normalizing and applying supported filters. Filament paginates that builder; the export service chunks the same builder.

### Keep report presentation read-only

The report page provides no row mutation, bulk action, inline select, checkbox, or toggle. Device rows may link to the existing authorized timeline.

### Use existing OpenSpout and queue infrastructure

No package is added. Export records remain the job hand-off and audit subject, and generated workbooks remain on the private local disk.

### Recheck access during request, generation, and download

Queued work can outlive a permission assignment. The export creator must still satisfy report, source, and pricing rules when sensitive content is generated; the downloader is checked independently.

### Do not compare prices across currencies

Supplier-reference rows retain `purchase_cost` and `currency_code`. Filters may narrow currency, supplier, country, or variant, but the service does not convert, normalize, or rank unlike currencies.

### Define usable valuation from available quantity

`available_quantity * cost_price` is the usable stock value. On-hand, reserved, damaged, and available remain visible as separate facts.
