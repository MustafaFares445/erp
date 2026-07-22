---

# Tasks: Warehouses, Locations & Read-Only Stock Visibility

**Input**: Design documents from `specs/002-warehouses-stock-visibility/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/](contracts/)

**Tests**: INCLUDED â€” mandated by the constitution (Principle VI), spec success criteria (SC-004 read-only guard, SC-002/SC-003 validation), and the `composer test` gate (100% type + code coverage). Write each story's test task first and confirm it fails before implementing.

**Organization**: Grouped by user story (US1 P1, US2 P2, US3 P3) so each is independently implementable and testable on the shared schema.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1 / US2 / US3 for story-phase tasks; Setup / Foundational / Polish carry no story label

## Path Conventions

Single Laravel application. Source under `app/`, migrations/seeders/factories under `database/`, translations under `lang/`, tests under `tests/` (Pest). Paths below are repo-relative. Filament resources use the nested directory layout matching `AdminModuleRegistry` namespaces (no registry edit â€” research R2).

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm a clean starting point (FI-0 foundation â€” panel gate, `inventory.*` permissions, policy trait, arch guard â€” is already committed).

- [X] T001 Confirm a green baseline: run `php artisan test --compact tests/Feature/Filament tests/Unit` and record that existing tests (incl. `PanelAccessTest`, `DashboardPageTest`, `ArchTest`) pass. No files changed in this task. **Result**: 60/60 passed.

**Checkpoint**: Baseline green â€” foundational work can begin.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Create the shared schema, models, enum, factories, validation, and the refined arch guard that ALL three user stories depend on. Table shapes are verbatim from [data-model.md](data-model.md) / ERD Â§6; all migrations MUST be engine-agnostic (research R11).

**âš ï¸ CRITICAL**: Blocks US1, US2, and US3. No resource/UI work may begin until this phase completes.

- [X] T002 [P] Create backed string enum `App\Enums\MovementType` with cases `Sale='sale'`, `Return='return'`, `Adjustment='adjustment'`, `Transfer='transfer'`, `Reservation='reservation'` in `app/Enums/MovementType.php` (research R7)
- [X] T003 [P] Create migration for the minimal `products` catalog stub (`id`, `name`, `is_active` default true, timestamps, `deleted_at`) in `database/migrations/2026_07_22_000002_create_products_table.php` (data-model catalog stub; Complexity Tracking / research R3)
- [X] T004 [P] Create migration for the minimal `product_variants` catalog stub (`id`, `product_id` FKâ†’products, `sku`, `name`, `is_active` default true, timestamps, `deleted_at`) in `database/migrations/2026_07_22_000003_create_product_variants_table.php`
- [X] T005 [P] Create migration for `warehouses` (`name`, `code`, `address` nullable, `is_active` default true, timestamps, `created_by`/`updated_by` FKâ†’users nullable, `deleted_at`; unique index on `code`) in `database/migrations/2026_07_22_000004_create_warehouses_table.php`
- [X] T006 [P] Create migration for `warehouse_locations` (`warehouse_id` FKâ†’warehouses, `name`, `code` nullable, `is_active` default true, timestamps, blame, `deleted_at`) in `database/migrations/2026_07_22_000005_create_warehouse_locations_table.php`
- [X] T007 [P] Create migration for `inventory_stocks` (`product_variant_id` FK, `warehouse_id` FK, `on_hand_quantity`/`reserved_quantity`/`available_quantity` decimal(15,3) default 0, `reorder_level` decimal(15,3) nullable, timestamps; **composite unique(`product_variant_id`,`warehouse_id`)**) in `database/migrations/2026_07_22_000006_create_inventory_stocks_table.php` (ERD Â§7 source of truth)
- [X] T008 [P] Create migration for `inventory_movements` (`product_variant_id` FK, `warehouse_id` FK, `movement_type` string(50), `quantity` signed decimal(15,3), `source_type` string(100) nullable, `source_id` bigint nullable, `notes` text nullable, `status` string(50), timestamps, blame; index `movement_type`/`status`/`created_at` and composite `source_type,source_id`) in `database/migrations/2026_07_22_000007_create_inventory_movements_table.php`
- [X] T009 [P] Create reusable `App\Models\Concerns\TracksBlameable` trait that sets `created_by`/`updated_by` from `auth()->id()` on `creating`/`updating` in `app/Models/Concerns/TracksBlameable.php` (research R9)
- [X] T010 [P] Create catalog stub models `App\Models\Product` and `App\Models\ProductVariant` (SoftDeletes; `ProductVariant` belongsTo Product, hasMany stocks/movements) in `app/Models/Product.php` and `app/Models/ProductVariant.php`
- [X] T011 [P] Create `App\Models\Warehouse` (SoftDeletes + TracksBlameable; hasMany `locations`, `stocks`, `movements`) and `App\Models\WarehouseLocation` (SoftDeletes + TracksBlameable; belongsTo Warehouse) in `app/Models/Warehouse.php` and `app/Models/WarehouseLocation.php`
- [X] T012 [P] Create `App\Models\InventoryStock` (belongsTo ProductVariant + Warehouse; decimal casts) and `App\Models\InventoryMovement` (belongsTo ProductVariant + Warehouse; `movement_type` cast to `MovementType`; read-only/immutable intent) in `app/Models/InventoryStock.php` and `app/Models/InventoryMovement.php`
- [X] T013 [P] Create `ProductFactory` + `ProductVariantFactory` (realistic `sku`/`name`) in `database/factories/ProductFactory.php` and `database/factories/ProductVariantFactory.php`
- [X] T014 [P] Create `WarehouseFactory` (unique `code`) + `WarehouseLocationFactory` in `database/factories/WarehouseFactory.php` and `database/factories/WarehouseLocationFactory.php`
- [X] T015 [P] Create `InventoryStockFactory` (states for low-stock / null-reorder) + `InventoryMovementFactory` (states per `MovementType`, signed quantity) in `database/factories/InventoryStockFactory.php` and `database/factories/InventoryMovementFactory.php`
- [X] T016 [P] Create `App\Data\Inventory\WarehouseData` (spatie/laravel-data) holding warehouse validation rules (`name` required max 255; `code` required max 50 + unique among live rows, ignoring the current record on edit) in `app/Data/Inventory/WarehouseData.php` (plan Â§2.5; contracts/warehouse-resource.md). **Note**: uniqueness-with-ignore is deliberately left to each channel (Filament's `unique(ignoreRecord: true)` vs a future API request) â€” see the class doc comment.
- [X] T017 Refine the FI-0 architecture guard in `tests/Unit/ArchTest.php`: keep "`App\Filament` must not use `App\Models\InventoryStock` / `App\Models\InventoryMovement`" for all Filament classes **except** the read-only namespaces `App\Filament\Resources\StockLevels` and `App\Filament\Resources\StockMovements`; add a comment explaining the intent is "no writes" and that the exception is backed by no-write behavior tests in US2/US3 (research R1; contracts/authorization.md)

**Checkpoint**: Migrate (`php artisan migrate`) succeeds; PHPStan type-checks the six models once all exist; `ArchTest` still green. User stories can now proceed (in parallel if staffed).

---

## Phase 3: User Story 1 - Set up and maintain the warehouse network (Priority: P1) ðŸŽ¯ MVP

**Goal**: Full CRUD for warehouses + their locations, with a read-only per-warehouse stock view, unique-code validation, active/inactive state, and soft-delete blocked when the warehouse is referenced.

**Independent Test**: As an admin with `inventory.warehouse.manage`, create a warehouse (unique code), add a location, deactivate it; confirm a duplicate code is rejected and a referenced warehouse cannot be deleted (deactivation offered) â€” all without any stock data required for the core flow.

### Tests for User Story 1 âš ï¸ (write first, confirm they fail)

- [X] T018 [P] [US1] Create `tests/Feature/Filament/WarehouseResourceTest.php` covering: create with unique code saves & lists; duplicate code â†’ validation error, no record (FR-002); add a location via the relation manager (FR-003); deactivate (`is_active=false`); soft-delete allowed when unreferenced but **blocked** when `inventory_stocks`/`inventory_movements` reference it, offering deactivation (FR-005/FR-006); `created_by`/`updated_by` populated; read-only `StockLevelsRelationManager` shows variant/on-hand/reserved/available with no write actions; admin lacking `inventory.warehouse.view` gets 403 + hidden nav (FR-020). Matches [contracts/warehouse-resource.md](contracts/warehouse-resource.md). (depends on Foundational)

### Implementation for User Story 1

- [X] T019 [US1] Create `App\Policies\WarehousePolicy` using `ChecksInventoryPermissions` (viewâ†’`inventory.warehouse.view`; create/update/restoreâ†’`inventory.warehouse.manage`; `delete`â†’manage **and** not-referenced guard; `forceDelete`â†’false) in `app/Policies/WarehousePolicy.php` (auto-discovered by Laravel; contracts/authorization.md)
- [X] T020 [US1] Create `WarehouseResource` + `Schemas/WarehouseForm` (fields from WarehouseData) + `Tables/WarehousesTable` (columns `code`,`name`,`is_active` badge,`locations_count`,`stocks_count`,`created_at`; filters `is_active`+trashed; search `code`/`name`) + `Pages/{List,Create,Edit,View}Warehouse` under `app/Filament/Resources/Warehouses/`; declare `$navigationGroup` = the inventory group's translation key and a `$navigationSort` in its reserved range (see `AdminModuleRegistry::groups()`) (depends on T019)
- [X] T021 [US1] Create `WarehouseLocationsRelationManager` (editable CRUD: `name` required, `code`, `is_active`) in `app/Filament/Resources/Warehouses/RelationManagers/WarehouseLocationsRelationManager.php` (depends on T020)
- [X] T022 [US1] Create read-only `StockLevelsRelationManager` (`$relationship = 'stocks'`; columns variant SKU+name, on-hand, reserved, available, reorder, low-stock indicator; **no create/edit/delete** â€” rely on Filament v5 default read-only mode) in `app/Filament/Resources/Warehouses/RelationManagers/StockLevelsRelationManager.php`. **Guard note**: reference stock only via the `stocks` relationship â€” do NOT `use App\Models\InventoryStock` here (this namespace is not excepted by the arch guard; using the relationship keeps it green â€” research R1). (depends on T020)
- [X] T023 [P] [US1] Edit `lang/en/admin.php`: add warehouse + location attribute/column label keys under `admin.resources.warehouses.*` (labels group already exists)

**Checkpoint**: US1 fully functional â€” `WarehouseResourceTest` green; warehouses/locations manageable; the inventory sidebar shows a real Warehouses screen. Shippable MVP.

---

## Phase 4: User Story 2 - See current stock on hand, reserved, and available (Priority: P2)

**Goal**: A strictly read-only stock levels screen with on-hand/reserved/available/reorder, low-stock flagging, and warehouse/variant/low-stock filters â€” no write path anywhere.

**Independent Test**: With stock data present, open Stock Levels, apply the low-stock filter (incl. boundary `available == reorder` and null-reorder cases) and warehouse/variant filters, and confirm no create/edit/delete control exists.

### Tests for User Story 2 âš ï¸ (write first, confirm they fail)

- [X] T024 [P] [US2] Create `tests/Feature/Filament/StockLevelResourceTest.php` covering: table shows variant/warehouse/on-hand/reserved/available/reorder (FR-009); `canCreate()===false` and no Edit/Delete/bulk-delete actions (FR-010, SC-004 â€” this backs the arch guard exception); low-stock filter lists rows where `available<=reorder` incl. the `==` boundary and **excludes** null-`reorder_level` rows (FR-011, SC-005); warehouse + variant/SKU search narrows results (FR-012); same variant in 2 warehouses â†’ 2 rows (FR-013, SC-008); admin lacking `inventory.stock.view` â†’ 403 + hidden nav (FR-020). Matches [contracts/stock-level-resource.md](contracts/stock-level-resource.md). (depends on Foundational)

### Implementation for User Story 2

- [X] T025 [US2] Create `App\Policies\InventoryStockPolicy` using `ChecksInventoryPermissions` (viewAny/viewâ†’`inventory.stock.view`; all write abilitiesâ†’false) in `app/Policies/InventoryStockPolicy.php` (contracts/authorization.md)
- [X] T026 [US2] Create read-only `StockLevelResource` (`canCreate()=>false`; `$model = InventoryStock::class`) + `Tables/StockLevelsTable` (columns + low-stock indicator via `reorder_level!==null && available<=reorder`; filters warehouse, low-stock only, variant/SKU search) + `Pages/{List,View}StockLevel` under `app/Filament/Resources/StockLevels/`; ViewAction only; header messaging that balances change only via adjustments/transfers (FR-014 â€” live links deferred to FI-3/FI-4); declare inventory `$navigationGroup`/sort (depends on T025)
- [X] T027 [P] [US2] Edit `lang/en/admin.php`: add stock-level column/label keys under `admin.resources.stock_levels.*`

**Checkpoint**: US1 + US2 both work independently â€” read-only stock visibility with low-stock flagging; arch guard still green (StockLevels namespace excepted, no-write behavior proven by T024).

---

## Phase 5: User Story 3 - Review the immutable history of every stock change (Priority: P3)

**Goal**: A strictly read-only, immutable movement ledger with signed/colored quantities, a full set of filters, and a per-movement infolist that renders the source document as a read-only cross-module link.

**Independent Test**: With movement data present, open Stock Movements, filter by type/warehouse/variant/date/source, open one movement's infolist, and confirm the source is a read-only link and no create/edit/delete control exists.

### Tests for User Story 3 âš ï¸ (write first, confirm they fail)

- [X] T028 [P] [US3] Create `tests/Feature/Filament/StockMovementResourceTest.php` covering: table shows date/variant/warehouse/type/signed-qty/source/status/creator (FR-016); increases vs decreases visibly distinguishable (US3 s2); `canCreate()===false`, no Edit/Delete/bulk actions (FR-015 â€” backs the arch guard exception); filters type/warehouse/variant/date-range/source narrow the ledger (FR-017); view page infolist loads (`assertOk`) and renders the source as a read-only link that is not editable, incl. a cross-module source (e.g. delivery note) (FR-018/FR-019, SC-006); admin lacking `inventory.movement.view` â†’ 403 + hidden nav (FR-020). Matches [contracts/movement-ledger-resource.md](contracts/movement-ledger-resource.md). (depends on Foundational)

### Implementation for User Story 3

- [X] T029 [US3] Create `App\Policies\InventoryMovementPolicy` using `ChecksInventoryPermissions` (viewAny/viewâ†’`inventory.movement.view`; all write abilitiesâ†’false) in `app/Policies/InventoryMovementPolicy.php`
- [X] T030 [US3] Create read-only `StockMovementResource` (`canCreate()=>false`; `$model = InventoryMovement::class`) + `Tables/StockMovementsTable` (columns incl. `movement_type` badge via `MovementType`, signed/colored `quantity`, read-only source reference; filters type/warehouse/variant/date-range/source) + `Schemas/StockMovementInfolist` (full detail + read-only source link, cross-module never editable â€” FR-019) + `Pages/{List,View}StockMovement` under `app/Filament/Resources/StockMovements/`; ViewAction only; declare inventory `$navigationGroup`/sort (depends on T029)
- [X] T031 [P] [US3] Edit `lang/en/admin.php`: add movement column/label keys under `admin.resources.stock_movements.*` (incl. movement-type labels)

**Checkpoint**: All three stories independently functional â€” the inventory sidebar shows Warehouses, Stock Levels, and Stock Movements as real screens; ledger is immutable and read-only.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Formatting, static analysis, full gate validation, and optional demo data.

- [X] T032 [P] Create optional dev-only `Database\Seeders\InventoryDemoSeeder` (sample products/variants, warehouses+locations, stock rows incl. a low-stock and a null-reorder case, and assorted movements incl. a cross-module source) in `database/seeders/InventoryDemoSeeder.php` for manual smoke (quickstart.md)
- [X] T033 [P] Run `vendor/bin/pint --dirty --format agent` and `vendor/bin/rector process` on all changed PHP files; resolve style/refactor findings
- [X] T034 Run `composer test:types` (PHPStan/Larastan max) and `composer test:type-coverage` (100%); fix findings at the root without adding baseline entries
- [X] T035 Run the full CI gate `composer test` (lint, static analysis, 100% type coverage, tests, 100% code coverage) and ensure it is green, including the unchanged `PanelAccessTest`/`DashboardPageTest`
- [X] T036 Execute [quickstart.md](quickstart.md) validation scenarios (warehouse CRUD, unique code, delete-blocking, read-only stock/movements, low-stock incl. boundary/null, filters, source link, permission gating, refined arch guard) and confirm each passes

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none â€” start immediately.
- **Foundational (Phase 2)**: after Setup â€” BLOCKS all user stories (schema + models + enum + factories + validation + arch-guard refinement).
- **User Stories (Phases 3â€“5)**: after Foundational. US1, US2, US3 build on the shared schema and are otherwise independent (each touches its own resource/policy/lang keys); they may proceed in parallel or in priority order.
- **Polish (Phase 6)**: after the targeted stories complete.

### User Story Dependencies

- **US1 (P1)**: needs Foundational (Warehouse/Location/Stock/Movement models for the resource, relation manager, and delete-guard). No dependency on US2/US3.
- **US2 (P2)**: needs Foundational (InventoryStock model, ProductVariant stub, arch-guard refinement T017). No dependency on US1/US3.
- **US3 (P3)**: needs Foundational (InventoryMovement model, MovementType enum, ProductVariant stub, arch-guard refinement T017). No dependency on US1/US2.

### Within Each Story

- Write the test task first and confirm it fails (Pest), then implement.
- Policy before resource (Filament reads the policy for authorization/visibility).
- Resource/table/pages before its relation managers (US1).

### Parallel Opportunities

- **Foundational**: T002â€“T016 are almost all `[P]` (distinct files) â€” migrations, enum, trait, models, factories, and the Data object can be authored together; type-check after all six models exist. T017 (ArchTest) is a single-file edit, best done once.
- **US1**: T018 `[P]` (test) authored while Foundational settles; T019â†’T020â†’(T021, T022) then; T023 `[P]` anytime in the phase.
- **US2**: T024 `[P]` first; T025â†’T026; T027 `[P]`.
- **US3**: T028 `[P]` first; T029â†’T030; T031 `[P]`.
- **Cross-story**: after Foundational, one developer can take US1, another US2, another US3 â€” the only shared file is `lang/en/admin.php` (T023/T027/T031 append distinct key groups; coordinate or sequence those three).

---

## Parallel Example: Foundational schema

```bash
# All migrations + enum + trait are distinct files â€” author together:
Task: "create_products_table migration"            # T003
Task: "create_product_variants_table migration"    # T004
Task: "create_warehouses_table migration"          # T005
Task: "create_warehouse_locations_table migration" # T006
Task: "create_inventory_stocks_table migration"    # T007
Task: "create_inventory_movements_table migration" # T008
Task: "MovementType enum"                           # T002
Task: "TracksBlameable trait"                        # T009

# Then the six models together (type-check once all exist): T010, T011, T012
```

## Parallel Example: User Story 2

```bash
# Test first:
Task: "Create tests/Feature/Filament/StockLevelResourceTest.php"   # T024
# Then policy + resource sequentially (resource reads the policy):
Task: "Create app/Policies/InventoryStockPolicy.php"               # T025
Task: "Create StockLevelResource + StockLevelsTable + pages"       # T026
```

---

## Implementation Strategy

### MVP First (User Story 1 only)

1. Phase 1 (Setup) â†’ Phase 2 (Foundational) â†’ Phase 3 (US1).
2. **STOP and VALIDATE**: `WarehouseResourceTest` green; warehouse/location CRUD works; unique code enforced; referenced warehouse cannot be deleted. Shippable master-data increment.

### Incremental Delivery

1. Setup + Foundational â†’ schema + models ready.
2. US1 â†’ warehouse network management (MVP, demo).
3. US2 â†’ read-only stock visibility with low-stock flagging (demo).
4. US3 â†’ immutable movement ledger with source links (demo).
5. Polish â†’ full `composer test` green + quickstart validation.

### Parallel Team Strategy

After Foundational: Developer A â†’ US1, Developer B â†’ US2, Developer C â†’ US3. Only `lang/en/admin.php` is shared (append distinct key groups); everything else is per-story.

---

## Notes

- [P] = different files, no dependency on incomplete tasks.
- **Self-contained scope** (project-owner decision, plan Complexity Tracking): this feature owns the inventory models/migrations + a minimal catalog stub (`products`/`product_variants`), with **no domain services** (FI-1/FI-2 perform no stock mutation). Catalog spec 005 later extends the stub tables additively.
- **Arch-guard intent** (research R1): the refinement forbids *writes* to stock/movement from Filament while permitting the two read-only resources to read them; the `StockLevelsRelationManager` (US1) stays clean by reading via the `stocks` relationship, not by importing the model.
- **Deferred (out of scope here)**: inventory domain services + write flows (FI-3/FI-4 adjustments/transfers), reservations/returns (FI-5), widgets/exports (FI-6), `lang/ar` restoration (Open Question #8), DB engine lock (Open Question #6), full catalog (spec 005).
- Policies are auto-discovered by Laravel naming convention (`App\Models\X` â†’ `App\Policies\XPolicy`) â€” no `AuthServiceProvider` registration needed.
- Commit after each task or logical group; keep changes small and reviewable (CLAUDE.md).
