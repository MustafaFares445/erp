# Implementation Plan: Warehouses, Locations & Read-Only Stock Visibility

**Branch**: `002-warehouses-stock-visibility` (work branch: `feature/filament-inventory-dashboard`) | **Date**: 2026-07-22 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/002-warehouses-stock-visibility/spec.md`

## Summary

Deliver the first three inventory screens on the FI-0 rails: (1) **WarehouseResource** — full CRUD master data for warehouses, with a locations relation manager and a read-only per-warehouse stock view; (2) **StockLevelResource** — a strictly read-only table of on-hand/reserved/available balances with low-stock flagging; and (3) **StockMovementResource** — a strictly read-only, immutable movement ledger with a per-movement infolist and read-only cross-module source links. Each resource declares a Laravel policy that delegates to the FI-0 `ChecksInventoryPermissions` trait, so authorization is identical to every other channel.

Per the project owner's decision (recorded in Complexity Tracking), this feature is **self-contained**: it creates the four backing inventory models + engine-agnostic migrations (`warehouses`, `warehouse_locations`, `inventory_stocks`, `inventory_movements`) faithfully to ERD §6, plus a minimal `product_variants` catalog stub the inventory foreign keys require, and warehouse validation rules — **without introducing any inventory domain service** (those belong to the FI-3/FI-4 write flows). Neither FI-1 (master data) nor FI-2 (read-only visibility) mutates stock, so no service layer is needed here; the FI-0 service-only mutation guard remains intact and is *refined* (see research R1) so read-only resources may read the ledger models without being able to write them.

## Technical Context

**Language/Version**: PHP 8.4 (composer requires `^8.4`).

**Primary Dependencies**: Laravel 13 (`^13.8`); **Filament v5** (`~5.0`, currently 5.7); `spatie/laravel-permission ^8.3` (authorization); `spatie/laravel-data ^4.23` (validation reuse for warehouse rules). No new dependencies.

**Storage**: Relational DB; engine unconfirmed (MySQL vs PostgreSQL — Open Question #6). Local test DB is SQLite. All new migrations MUST be engine-agnostic (Blueprint methods only, `decimal(15,3)` for quantities per ERD, no native enum columns). Permission tables, `users`, and `user_type` already migrated (FI-0).

**Testing**: Pest v4 (Feature + Unit + `arch()` in `tests/Unit/ArchTest.php`); Larastan v3 at level `max` with `parseModelCastsMethod: true` (set in FI-0); Pint; Rector. CI gate `composer test` enforces **100% type coverage and 100% code coverage** — every new file must be fully typed and covered.

**Target Platform**: Laravel web app; Filament admin panel at `/admin` with session auth (FI-0). These resources auto-discover into the committed `inventory` navigation group; no registry edit is needed (the classes already exist in `AdminModuleRegistry` — see research R2).

**Project Type**: Single Laravel application (API + Filament admin surface in one codebase).

**Performance Goals**: No feature-specific throughput target. Hard rule: **stock balances MUST NOT be cached** (SYSTEM_ARCHITECTURE §9); read-only tables query current data directly. Stable lookups (warehouse option lists in filters) MAY be cached.

**Constraints**: No hard-delete of any inventory record; warehouse soft-delete blocked when referenced by stock/movements; stock and movement resources expose **zero** create/edit/delete paths; authorization via Spatie policies reusing the FI-0 trait (no forked ACL); must keep `composer test` (incl. 100% coverage) green; must not break `PanelAccessTest`/`DashboardPageTest`.

**Scale/Scope**: 3 Filament resources + 2 relation managers; 4 inventory models + 1 catalog stub model; 5 migrations; warehouse validation (Data object); factories + demo seeder; `lang/en/admin.php` attribute keys; policies (3); refined arch guard; feature + unit tests. **No** domain services, **no** write/mutation flows, **no** widgets/exports (FI-6), **no** adjustments/transfers/reservations/returns (FI-3–FI-5).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design.*

| Principle | Gate | Status |
|---|---|---|
| I. Specification-First | Work derived from approved spec + plan doc; DB design finalized before coding | ✅ PASS — derived from spec.md and `FILAMENT_INVENTORY_DASHBOARD_PLAN.md` §4–§5; table shapes taken verbatim from ERD §6 (design is finalized there). |
| II. Domain-Driven Modular Monolith | Thin controllers; business rules in services; no unrelated refactors | ✅ PASS — no controllers added (Filament); FI-1/FI-2 carry **no business rules** (master data + read-only), so no service is warranted; the models/migrations created here are the inventory module's own tables, not an unrelated refactor. |
| III. Financial & Inventory Integrity (NON-NEGOTIABLE) | Every stock change goes through a service that writes a movement; stock source of truth is `product_variant_id + warehouse_id`; confirmed docs not deleted | ✅ PASS — this feature **changes no stock**. It surfaces the `(product_variant_id, warehouse_id)` unique balance read-only and the immutable ledger read-only. The FI-0 arch guard (refined, R1) still forbids Filament from writing stock/movements. No delete path on ledgers. |
| IV. Unified Access, Media & Payment | Spatie permission; `user_type` channel; no custom ACL | ✅ PASS — three policies delegate to the FI-0 `ChecksInventoryPermissions` trait mapping abilities → seeded `inventory.*` permissions; no bespoke authorization. |
| V. AI Isolation & Human Oversight | N/A | ✅ N/A |
| VI. Engineering Discipline | Tests per rule; audit sensitive actions; report changes; no unrelated refactors | ✅ PASS — every behavior ships with a test; no *sensitive* (stock-changing) action exists here so no audit write is due yet; changes reported per task. |

**Gate result: PASS.** One coordination decision (this feature creating inventory + catalog-stub models ahead of the backend catalog/inventory spec 005) is recorded in Complexity Tracking.

**Post-design re-check (after Phase 1)**: Still PASS. The design adds only read paths over ledger models plus warehouse CRUD; it introduces no caching of balances, no forked ACL, no new dependency, and no stock mutation. The refined arch guard *narrows* an over-broad FI-0 rule while preserving (and testing) the no-write guarantee — it strengthens Principle III's enforceability rather than weakening it. No new Complexity entries needed.

## Project Structure

### Documentation (this feature)

```text
specs/002-warehouses-stock-visibility/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output (warehouse, stock-level, movement-ledger, authorization)
├── checklists/
│   └── requirements.md  # From /speckit-specify
└── tasks.md             # Created later by /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Enums/
│   └── MovementType.php                              # NEW — sale|return|adjustment|transfer|reservation (backed string enum for movement_type badge)
├── Models/
│   ├── Warehouse.php                                 # NEW — SoftDeletes; hasMany locations, stocks, movements; created_by/updated_by
│   ├── WarehouseLocation.php                         # NEW — SoftDeletes; belongsTo Warehouse
│   ├── InventoryStock.php                            # NEW — READ-ONLY in Filament; belongsTo Warehouse + ProductVariant; unique (product_variant_id, warehouse_id)
│   ├── InventoryMovement.php                         # NEW — READ-ONLY/immutable; belongsTo Warehouse + ProductVariant; morph-style source_type/source_id
│   └── ProductVariant.php                            # NEW (catalog stub, see Complexity Tracking) — sku, name, is_active; referenced read-only by stock/movements
├── Data/
│   └── Inventory/
│       └── WarehouseData.php                         # NEW — spatie/laravel-data object holding warehouse validation rules (reused by Filament form; API later)
├── Filament/
│   ├── AdminModuleRegistry.php                       # UNCHANGED (already references these resource classes; they resolve automatically — research R2)
│   └── Resources/
│       ├── Warehouses/
│       │   ├── WarehouseResource.php                 # NEW — full CRUD; soft deletes; search code/name
│       │   ├── Pages/{List,Create,Edit,View}Warehouse.php
│       │   ├── Schemas/WarehouseForm.php             # form fields (name, code unique, address, is_active)
│       │   ├── Tables/WarehousesTable.php            # columns + filters (is_active, trashed) + counts
│       │   └── RelationManagers/
│       │       ├── WarehouseLocationsRelationManager.php  # NEW — editable locations CRUD
│       │       └── StockLevelsRelationManager.php         # NEW — READ-ONLY per-warehouse stock view
│       ├── StockLevels/
│       │   ├── StockLevelResource.php                # NEW — READ-ONLY (canCreate=false; no edit/delete); low-stock filter
│       │   ├── Pages/{List,View}StockLevel.php
│       │   └── Tables/StockLevelsTable.php
│       └── StockMovements/
│           ├── StockMovementResource.php             # NEW — READ-ONLY/immutable ledger
│           ├── Pages/{List,View}StockMovement.php
│           ├── Schemas/StockMovementInfolist.php     # per-movement detail + read-only source link
│           └── Tables/StockMovementsTable.php
├── Policies/
│   ├── WarehousePolicy.php                           # NEW — uses ChecksInventoryPermissions; view→warehouse.view, create/update/delete→warehouse.manage; delete blocked when referenced
│   ├── InventoryStockPolicy.php                      # NEW — viewAny/view→stock.view; create/update/delete → false (deny-by-default)
│   └── InventoryMovementPolicy.php                   # NEW — viewAny/view→movement.view; all writes → false
└── Providers/Filament/AdminPanelServiceProvider.php  # UNCHANGED

database/
├── migrations/
│   ├── 2026_xx_xx_create_product_variants_table.php  # NEW — minimal catalog stub (see Complexity Tracking)
│   ├── 2026_xx_xx_create_warehouses_table.php        # NEW — ERD §6
│   ├── 2026_xx_xx_create_warehouse_locations_table.php # NEW — ERD §6
│   ├── 2026_xx_xx_create_inventory_stocks_table.php  # NEW — ERD §6; unique (product_variant_id, warehouse_id)
│   └── 2026_xx_xx_create_inventory_movements_table.php # NEW — ERD §6
├── factories/
│   ├── WarehouseFactory.php / WarehouseLocationFactory.php  # NEW
│   ├── InventoryStockFactory.php / InventoryMovementFactory.php # NEW
│   └── ProductVariantFactory.php                     # NEW
└── seeders/
    └── InventoryDemoSeeder.php                        # NEW (optional, dev only) — sample warehouses/variants/stock/movements for manual smoke

lang/
└── en/admin.php                                       # EDIT — attribute/column labels for warehouses, stock_levels, stock_movements
                                                        # (lang/ar restoration remains deferred — Open Question #8)

tests/
├── Feature/Filament/
│   ├── WarehouseResourceTest.php                      # NEW — CRUD, unique code, delete-blocked-when-referenced, deactivate, permission hide/403
│   ├── StockLevelResourceTest.php                     # NEW — read-only (no create/edit/delete), low-stock filter (incl. boundary + null reorder), per-warehouse/variant filter
│   └── StockMovementResourceTest.php                  # NEW — read-only/immutable, signed quantity, filters, infolist source link, cross-module link not editable
├── Unit/
│   └── ArchTest.php                                   # EDIT — refine inventory write-guard (R1): read-only resource namespaces excluded; all other App\Filament still banned from stock/movement models
└── (policy behavior covered within the feature tests above)
```

**Structure Decision**: Single Laravel app. Inventory models live in `app/Models`, the movement enum in `app/Enums` (repo arch preset requires enums there), warehouse validation in `app/Data/Inventory` (spatie/laravel-data), Filament resources in the nested `app/Filament/Resources/{Warehouses,StockLevels,StockMovements}` directories matching the registry's declared namespaces, and policies in `app/Policies`. The committed `AdminModuleRegistry` already imports `WarehouseResource`, `StockLevelResource`, and `StockMovementResource`, so creating these classes replaces their sidebar placeholders automatically (registry §1.2) with **no registry edit**.

## Requirement coverage

| Spec item | Phase | Outcome |
|---|---|---|
| US1 / FR-001…FR-008, SC-001…SC-003 | FI-1 | **Fully realized** — WarehouseResource CRUD, unique code, locations relation manager, active/inactive, soft-delete blocked-when-referenced, search/filters, read-only location/stock counts. |
| US2 / FR-009…FR-014, SC-004, SC-005, SC-007, SC-008 | FI-2 | **Fully realized** — read-only StockLevelResource; no write controls; low-stock flag (`available ≤ reorder`, null reorder ⇒ not low); warehouse/low-stock/variant filters; one row per `(variant, warehouse)`. FR-014 sanctioned-write navigation is present as messaging; live links to Adjustment/Transfer become active when FI-3/FI-4 ship. |
| US3 / FR-015…FR-019, SC-006 | FI-2 | **Fully realized** — read-only/immutable StockMovementResource; signed/colored quantity; type/warehouse/variant/date/source filters; per-movement infolist with read-only source link; cross-module source rendered as non-editable link. |
| FR-020 (inherited FI-0) | FI-1/FI-2 | **Realized per resource** — each policy delegates to `ChecksInventoryPermissions`; navigation hidden + direct-URL 403 when the matching `inventory.*` view permission is absent. |
| FR-021 / FR-011 (FI-0 "no ledger delete") | FI-2 | **Realized here** — the first ledger resources ship with a tested absence of create/edit/delete; deny-by-default policies confirm it. |

## Complexity Tracking

| Violation / Deviation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| This feature creates the inventory models + migrations (`warehouses`, `warehouse_locations`, `inventory_stocks`, `inventory_movements`) ahead of the backend catalog/inventory spec (`005-products-variants-warehouses-inventory` in the constitution's extraction map). | FI-1/FI-2 cannot exist without these tables, and `app/Models` currently holds only `User`. Table design is already finalized in ERD §6 (satisfying Principle I's "DB design finalized before implementation"). The models carry no business logic — plain Eloquent with relationships, casts, and soft deletes — so they do not usurp the service layer. **Project-owner decision: own them here to unblock the dashboard track.** | Waiting for spec 005 leaves the entire approved inventory-dashboard track (001–00N) blocked indefinitely on an unscheduled backend phase. The migrations are additive and ERD-faithful, so spec 005 extends them rather than colliding. |
| A **minimal `product_variants` + `products` catalog stub** is created because `inventory_stocks.product_variant_id` and `inventory_movements.product_variant_id` are NOT NULL foreign keys (ERD §6) and FR-009/FR-016 require displaying the variant SKU + name. | Read-only stock/movement rows are meaningless and untestable without a variant to reference; factories and FK constraints need a real `product_variants` row. The stub carries only ERD columns needed now (`sku`, `name`, `is_active`, timestamps, soft deletes; `product_id` FK to a minimal `products`). | Making the FK nullable or omitting it would deviate from ERD §6 (violating Principle I); stubbing the *entire* catalog (categories, attributes, values, pricing) would balloon scope far beyond FI-1/FI-2. The narrow two-table stub is the smallest ERD-faithful choice, and is explicitly flagged for reconciliation/extension by catalog spec 005. |
| The FI-0 architecture guard (`App\Filament` must not use `InventoryStock`/`InventoryMovement`) is **refined** rather than left as-is. | Read-only resources must *read* these models to display them; the original blanket ban would fail the build the moment `StockLevelResource` references its model. The intent was always "no **writes**," not "no reference." (See research R1.) | Deleting the guard would lose the no-write protection entirely; keeping it unchanged would make the read-only resources impossible. The refinement scopes the ban to all Filament classes *except* the two sanctioned read-only resource namespaces, which are independently tested to expose no create/edit/delete path — preserving the guarantee while allowing legitimate reads. |
