# Research: Complete Excel Import Workflow

## Existing Implementation

- OpenSpout already streams XLSX rows and writes the template.
- Uploads and templates already use the private `local` disk.
- `ParseCatalogImport` is queued; confirmation is currently synchronous.
- `InventoryReceivingService` already owns receipt confirmation, serialized-unit validation, lot creation, stock, movement, pricing, alerts, and audit effects.
- Import rows currently retain payload, validation errors, status, and applied time but not runtime failures or affected identifiers.

## Decisions

### D1 - Explicit backed enums

Use backed enums for run and row states, cast by the models. This prevents free-form transition drift and keeps stored values stable for Filament filters.

### D2 - Queued confirmation

The Filament confirmation action only locks and transitions an eligible run to `Applying`, then dispatches `ApplyCatalogImport` after commit. The job owns long-running application and terminal state.

### D3 - Idempotency boundary

Each row is locked before applying. Terminal applied/rejected rows are skipped. A receipt group persists its shared receipt identifier to all member results inside the same transaction as receipt confirmation.

### D4 - Independent groups

Catalog-only rows run in independent row transactions. Inventory rows are grouped by normalized warehouse and optional supplier code, and each group runs in its own transaction. A group exception marks only its rows rejected and permits later groups to continue.

### D5 - Attribute validation

Header columns beginning with `attribute_` must match an active attribute code. Select values must resolve to active records during parse. Text values are normalized case-insensitively and created only during apply.

### D6 - Reports

Generate CSV result files from persisted row outcomes on the private disk. The detailed report contains every row and error; the summary report contains run counters and terminal metadata. Downloads use authorized Filament actions.

## Rejected Alternatives

- Writing stock directly from the importer was rejected because it bypasses movement, lot, pricing, alert, and audit invariants.
- One transaction for the complete run was rejected because one bad supplier or warehouse group would roll back otherwise valid work.
- Creating select values during parse was rejected because preview must be non-mutating.
- Treating retries as new runs was rejected because queue delivery is at-least-once and row application must be intrinsically idempotent.
