# Phase 1 Data Model: Warehouses, Locations & Read-Only Stock Visibility

All shapes below are taken verbatim from **ERD §6** (the finalized design). This feature creates the four inventory tables it surfaces, plus a minimal catalog stub the foreign keys require. Column types use engine-agnostic Blueprint equivalents (research R11); quantities are `decimal(15,3)`, money-adjacent variant fields `decimal(15,2)`.

## Entities created by this feature

### `warehouses` → `App\Models\Warehouse` (full CRUD, soft deletes)

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned (PK) | No | auto | |
| `name` | string(255) | No | | Required (FR-001) |
| `code` | string(50) | No | | **Unique** (FR-002); searchable |
| `address` | text | Yes | null | Textarea |
| `is_active` | boolean | No | true | Active/inactive toggle (FR-004) |
| `created_at` / `updated_at` | timestamp | No | now | |
| `created_by` / `updated_by` | bigint unsigned (FK users) | Yes | null | Blame (research R9) |
| `deleted_at` | timestamp | Yes | null | Soft delete (FR-006) |

- **Relationships**: `hasMany` WarehouseLocation, InventoryStock, InventoryMovement.
- **Indexes**: unique(`code`); index(`is_active`); FK indexes on blame columns.
- **Rules**: `name` required; `code` required + unique (ignoring soft-deleted? — unique on live rows; see WarehouseData in contracts/authorization). Delete blocked when any stock/movement references it (policy, research R5).

### `warehouse_locations` → `App\Models\WarehouseLocation` (CRUD via relation manager, soft deletes)

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned (PK) | No | auto | |
| `warehouse_id` | bigint unsigned (FK warehouses) | No | | Parent |
| `name` | string(255) | No | | Required (FR-003) |
| `code` | string(50) | Yes | null | Optional internal code |
| `is_active` | boolean | No | true | |
| `created_at` / `updated_at` | timestamp | No | now | |
| `created_by` / `updated_by` | bigint unsigned (FK users) | Yes | null | Blame |
| `deleted_at` | timestamp | Yes | null | Soft delete |

- **Relationships**: `belongsTo` Warehouse.
- **Indexes**: FK index on `warehouse_id`; index(`code`).

### `inventory_stocks` → `App\Models\InventoryStock` (READ-ONLY in Filament, no soft delete)

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned (PK) | No | auto | |
| `product_variant_id` | bigint unsigned (FK product_variants) | No | | Source of truth part 1 |
| `warehouse_id` | bigint unsigned (FK warehouses) | No | | Source of truth part 2 |
| `on_hand_quantity` | decimal(15,3) | No | 0 | Physical |
| `reserved_quantity` | decimal(15,3) | No | 0 | Reserved |
| `available_quantity` | decimal(15,3) | No | 0 | **Stored**, service-maintained (research R4) |
| `reorder_level` | decimal(15,3) | Yes | null | Null ⇒ never low-stock |
| `created_at` / `updated_at` | timestamp | No | now | |

- **Relationships**: `belongsTo` ProductVariant, Warehouse.
- **Indexes**: **unique(`product_variant_id`, `warehouse_id`)** (ERD §7 / Principle III source of truth); FK indexes.
- **Rules (read model)**: low-stock ⇔ `reorder_level IS NOT NULL AND available_quantity <= reorder_level`. No create/edit/delete anywhere in Filament (FR-010).

### `inventory_movements` → `App\Models\InventoryMovement` (READ-ONLY / immutable, no soft delete)

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned (PK) | No | auto | |
| `product_variant_id` | bigint unsigned (FK product_variants) | No | | |
| `warehouse_id` | bigint unsigned (FK warehouses) | No | | |
| `movement_type` | string(50) | No | | Cast to `MovementType` enum (research R7) |
| `quantity` | decimal(15,3) | No | | **Signed** ± (research R8) |
| `source_type` | string(100) | Yes | null | Source document type (may be another module) |
| `source_id` | bigint unsigned | Yes | null | Source document id |
| `notes` | text | Yes | null | |
| `status` | string(50) | No | draft/pending | Workflow status (display only here) |
| `created_at` / `updated_at` | timestamp | No | now | |
| `created_by` / `updated_by` | bigint unsigned (FK users) | Yes | null | Written by future services, not here |

- **Relationships**: `belongsTo` ProductVariant, Warehouse; `source` resolved from `source_type`/`source_id` (rendered as a read-only reference; a live morph relation is optional and only for internal source types that exist — cross-module sources render as a labeled read-only link, never an editable relation — FR-019).
- **Indexes**: FK indexes; index(`movement_type`), index(`status`), index(`created_at`), composite index(`source_type`,`source_id`).
- **Rules**: immutable — no create/edit/delete in Filament (FR-015).

### Catalog stub (Complexity Tracking, research R3)

#### `products` → `App\Models\Product` (minimal stub, soft deletes)

| Column | Type | Nullable | Default |
|---|---|---|---|
| `id` | bigint unsigned (PK) | No | auto |
| `name` | string(255) | No | |
| `is_active` | boolean | No | true |
| `created_at` / `updated_at` | timestamp | No | now |
| `deleted_at` | timestamp | Yes | null |

#### `product_variants` → `App\Models\ProductVariant` (minimal stub, soft deletes)

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned (PK) | No | auto | |
| `product_id` | bigint unsigned (FK products) | No | | ERD NOT NULL FK |
| `sku` | string(100) | No | | Displayed with stock/movements (FR-009/FR-016) |
| `name` | string(255) | No | | Display name |
| `is_active` | boolean | No | true | |
| `created_at` / `updated_at` | timestamp | No | now | |
| `deleted_at` | timestamp | Yes | null | |

- **Scope note**: Only the columns FI-2 needs. Attributes, values, pricing tiers, categories, product files (ERD) are **out of scope** and added additively by catalog spec 005. `unit_price`/`cost_price` etc. are **not** created here (StockValueByWarehouse cost math is FI-6, not this feature).
- **Referenced read-only**: Filament never manages catalog data in this feature; `ProductVariant` appears only as a read-only reference in stock/movement views and their filters.

## Enums (new)

### `App\Enums\MovementType` (backed string)

| Case | Value |
|---|---|
| `Sale` | `sale` |
| `Return` | `return` |
| `Adjustment` | `adjustment` |
| `Transfer` | `transfer` |
| `Reservation` | `reservation` |

Drives the `movement_type` badge color + the type filter option list. Extension-safe (add cases, don't renumber).

## Reused / referenced (not created here)

- **`users`** — blame FKs (`created_by`/`updated_by`) and the acting administrator on movements.
- **Spatie `roles`/`permissions`** — authorization via the FI-0 `inventory.*` catalogue; no change.
- **`audit_logs`** — not written by this feature (no sensitive stock action occurs here; audit is a service side-effect in FI-3+).

## State & transitions

- **Warehouse / location**: `is_active` true⇄false (deactivation); soft-delete (recoverable) allowed only when unreferenced. No document lifecycle.
- **Stock / movement**: none here — they are read models. Movement `status` is displayed but never transitioned by this feature (transitions belong to FI-3/FI-4 via `StatusTransitionService`).

## Validation rules

| Rule | Where enforced |
|---|---|
| Warehouse `name` required; `code` required + unique | `WarehouseData` rules (spatie/laravel-data), reused by the Filament form; unique on live (non-soft-deleted) rows |
| Location `name` required | Relation-manager form rules |
| Panel access requires `user_type === Admin` | `User::canAccessPanel()` (FI-0) |
| Warehouse view/manage; stock view; movement view gated by matching `inventory.*` permission | `WarehousePolicy` / `InventoryStockPolicy` / `InventoryMovementPolicy` via `ChecksInventoryPermissions` (FI-0) |
| Warehouse delete blocked when referenced by stock/movements; hard delete never allowed | `WarehousePolicy::delete()` / `forceDelete()` (research R5) |
| Stock/movement expose no create/edit/delete | `canCreate()=false` + absent write actions + deny-by-default policies (research R5, R6); refined arch guard (research R1) |
| Stock unique per `(product_variant_id, warehouse_id)` | DB composite unique index |
| Balances never cached | read-only tables query current data (research R4); no cache layer added |
