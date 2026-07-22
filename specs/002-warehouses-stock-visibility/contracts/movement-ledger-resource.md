# Contract: StockMovementResource (FI-2, READ-ONLY / IMMUTABLE)

`App\Filament\Resources\StockMovements\StockMovementResource` — immutable ledger over `inventory_movements`. **No create/edit/delete anywhere.**

## Read-only mechanics (research R6)

- `canCreate(): bool => false`; no write actions or bulk delete registered.
- Pages: `List`, `View` only. View page uses an **infolist** (research R6).
- Row action: `ViewAction` only.

## Table

- **Columns**: `created_at` (date), product variant (`sku`+`name`), warehouse, `movement_type` (badge, colored per `MovementType` — research R7), `quantity` (signed ±, colored increase/decrease — research R8), source reference (`source_type` + `source_id`, read-only), `status`, `created_by` (acting user).
- **Filters**: movement type, warehouse, variant, date range (`created_at`), source type.
- **No** create/edit/delete; no bulk mutation.

## Infolist (View page)

- Full movement detail (all columns above, expanded).
- **Source link**: renders `source_type`/`source_id` as a read-only reference. When the source belongs to another module (e.g. delivery note / credit note — Sales), it is a **read-only cross-module link**, never an editable relation (FR-019, §0 module boundary).

## Behavior contract

| Given | When | Then |
|---|---|---|
| admin with `inventory.movement.view` | open ledger | each entry shows date, variant, warehouse, type, signed qty, source, status, creator (FR-016) |
| a decrease and an increase | listed | quantities visibly distinguishable ± (FR-016, US3 s2) |
| movement sourced from another module's doc | open movement | source is a read-only link; not editable from inventory (FR-019, SC-006) |
| any admin | search for create/edit/delete control | none exists (FR-015) |
| type/warehouse/variant/date/source filters | apply any combination | ledger narrows to matches (FR-017) |
| admin lacking `inventory.movement.view` | direct URL | 403; hidden in nav (FR-020) |

## Authorization

`InventoryMovementPolicy`: viewAny/view → `inventory.movement.view`; all write abilities → false. See authorization.md.
