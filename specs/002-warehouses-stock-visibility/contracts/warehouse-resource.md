# Contract: WarehouseResource (FI-1)

`App\Filament\Resources\Warehouses\WarehouseResource` — full CRUD master data. Backs `warehouses` (+ `warehouse_locations`). Auto-discovered into the `inventory` navigation group (no registry edit — research R2).

## Form (create/edit)

| Field | Component | Rules |
|---|---|---|
| `name` | text | required, max 255 |
| `code` | text | required, max 50, **unique** (ignore current record on edit; unique among live rows) |
| `address` | textarea | nullable |
| `is_active` | toggle | default true |

Validation rules sourced from `App\Data\Inventory\WarehouseData` (spatie/laravel-data) so the Filament form and a future API request share one rule set (plan §2.5 posture).

## Table

- **Columns**: `code` (searchable, sortable), `name` (searchable, sortable), `is_active` (badge), `locations_count` (read-only count), `stocks_count` (read-only count), `created_at`.
- **Filters**: `is_active` (ternary), trashed (soft-deleted).
- **Row actions**: View, Edit, Delete (Delete visible only when policy allows — i.e. not referenced).
- **No** bulk force-delete.

## Relation managers (on View/Edit page)

### WarehouseLocationsRelationManager (`warehouse_locations`) — editable

- Fields: `name` (required), `code` (nullable), `is_active` (toggle).
- Columns: `code`, `name`, `is_active`.
- Create/edit/delete enabled (locations are master data). Soft-deletable.

### StockLevelsRelationManager (`inventory_stocks`) — READ-ONLY

- Columns: product variant (SKU + name), `on_hand_quantity`, `reserved_quantity`, `available_quantity`, `reorder_level`, low-stock indicator.
- Relies on Filament v5 default read-only mode on the View page (research R6). No create/edit/delete registered.

## Behavior contract

| Given | When | Then |
|---|---|---|
| admin with `inventory.warehouse.manage` | create warehouse with unique code | saved; appears in sidebar warehouse list (FR-001) |
| duplicate code | submit | field-level validation error; no record (FR-002) |
| existing warehouse | add a location | location saved and listed (FR-003) |
| warehouse referenced by stock/movements | attempt delete | delete action absent/denied; deactivation available (FR-005) |
| unreferenced warehouse | delete | soft-deleted (recoverable), not destroyed (FR-006) |
| admin lacking `inventory.warehouse.view` | open inventory group / direct URL | resource hidden; URL 403 (FR-020) |

## Authorization

`WarehousePolicy` (see authorization.md): view→`inventory.warehouse.view`; create/update/restore→`inventory.warehouse.manage`; delete→`manage` + not-referenced guard; forceDelete→false.
