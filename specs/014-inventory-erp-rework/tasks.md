---

description: "Task list for the Inventory Module ERP-Pattern Rework"
---

# Tasks: Inventory Module ERP-Pattern Rework

**Input**: Design documents from `/specs/014-inventory-erp-rework/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/](./contracts/)

**Tests**: Test tasks are **included and mandatory**, not optional. Constitution Principle VI
requires a test for every implemented business rule, `CLAUDE.md` requires every change to be
programmatically tested, and [research.md R-008](./research.md) fixes the approach. Write each
story's tests before its implementation and confirm they fail first.

**Organization**: Grouped by user story so each is independently implementable and testable.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel — different files, no dependency on incomplete work
- **[Story]**: US1–US5, mapping to the spec's user stories

Every task names the file it changes. Five tasks are deliberately command-only gates rather than
file edits — T002, T026, T093, T098 and T099 — because their output is a pass/fail verdict on work
already done, not a new artifact.

## Path Conventions

Modular monolith, existing layout (see plan.md → Structure Decision). Domain services in
`app/Services/Inventory/`, Filament resources in `app/Filament/Resources/<Plural>/`, models flat
in `app/Models/`, tests in `tests/Feature/Inventory/`. No new top-level directory.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Establish a clean baseline and the shared translation surface.

- [X] T001 Commit or stash the in-flight catalog-setup consolidation and `database/migrations/2026_07_25_120000_add_warehouse_location_id_to_inventory_tracking_tables.php` so this feature starts from a clean diff (A-011)
- [X] T002 Run `composer test` and record the passing suite list as the green baseline that SC-007 is measured against — command-only gate, no file edit. **Final baseline: 340/340 Pest tests, 100% type coverage, PHPStan clean (0 errors), Pint/Rector clean. Code coverage: 99.3% with exactly one pre-existing, out-of-scope line uncovered — `CatalogImportService.php:200`, the `isBlankRow()` skip branch in the feature-008 XLSX importer. Verified experimentally (see commit) that OpenSpout's writer/reader silently drops any row where every cell is blank before the row ever reaches application code, so this branch cannot be exercised through the same library the importer itself uses to read real uploads; it is very likely unreachable dead code. Per A-006 this feature does not modify domain services outside its own scope, so it is left as a documented, known gap rather than "fixed" by touching feature 008's unrelated logic. Two other genuine gaps found in the same baseline run — `InventoryReceiptItem::location()` and `WarehouseLocation::serializedUnits()`/`lots()`, both directly relevant to the location tracking this feature depends on — were closed with a real test.**
- [X] T003 [P] Add `admin.inventory.operation.*`, `admin.inventory.package.*` and revised `admin.sections.*` key groups to `lang/en/admin.php`
- [X] T004 [P] Add the matching Arabic keys to `lang/ar/admin.php`, including stage labels that read correctly right-to-left (SRS §5.1). **Discovery: no `lang/ar/` directory existed at all before this task — created it, plus `lang/vendor/filament-panels/ar/layout.php` overriding Filament's `direction` key to `rtl` (verified via tinker). Arabic coverage in `admin.php` is scoped to the Inventory module only; other modules fall back to English per-key via `fallback_locale`, which is correct since they're outside FR-040's "new and reworked screens" scope.**

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Permissions and the architecture guard rail that all later phases are written against.

**⚠️ CRITICAL**: No user story work begins until this phase completes.

- [X] T005 Extend `app/Enums/InventoryPermission.php` with operation and package cases — additive only, remove no existing case (A-006). Added `DeliveryView/Create/Confirm` (Receipt and Transfer permissions already existed from features 003/004) and `PackageView`/`PackageManage`.
- [X] T006 Seed the new permission cases onto existing roles in the permission seeder under `database/seeders/`. **No code change needed** — `InventoryPermissionSeeder` already generically iterates `InventoryPermission::values()`, so new cases are picked up automatically; confirmed via `InventoryPermissionSeederTest.php`, which asserts against the enum rather than a hardcoded list.
- [X] T007 Add an architecture assertion to `tests/Unit/ArchTest.php` that `App\Filament\Resources\InventoryOperations` and `App\Filament\Resources\Packages` do not use `InventoryStock` or `InventoryMovement` — a new rule, never a new allowlist exception (P-2). **No new assertion needed** — the existing single rule targets the whole `App\Filament` namespace and excludes only an explicit `ignoring()` list, so it already covers any namespace not on that list, including these two once they exist. Added a comment documenting that they must never be added to `ignoring()`.

**Checkpoint**: Permissions in place, guard rail active. User stories may now begin.

---

## Phase 3: User Story 1 — One predictable lifecycle for every warehouse operation (Priority: P1) 🎯 MVP

**Goal**: Receipts, deliveries and inter-warehouse transfers become one document with one
lifecycle, one line editor and one confirmation step.

**Independent Test**: Create one operation of each type, walk each through its full lifecycle, and
confirm the stage names, line editor, confirmation prompt and resulting ledger entries follow one
identical pattern — with `InTransit` as the sole per-type difference.

**⚠️ Highest-risk phase.** The backfill (T024–T026) is the only balance-adjacent migration in this
feature. T026 gates everything after it.

### Tests for User Story 1

> Write these first. Confirm they fail before implementing.

- [ ] T008 [P] [US1] Stage-machine tests — legal and illegal transitions, `InTransit` rejected for non-transfer types (V-03) — in `tests/Feature/Inventory/OperationLifecycleTest.php`
- [ ] T009 [P] [US1] Custody-rule tests — receipt gains at Done, delivery loses at Done, transfer loses at InTransit and gains at Done; one movement row per balance change (FR-003, FR-011) — in `tests/Feature/Inventory/OperationStockEffectTest.php`
- [ ] T010 [P] [US1] In-transit tests — quantity counted against neither warehouse between dispatch and receipt, visible in Stock Levels (FR-007, SRS §3.12) — in `tests/Feature/Inventory/OperationInTransitTest.php`
- [ ] T011 [P] [US1] Guard tests — available never negative, Done immutable and undeletable, concurrent confirmation resolves once, duplicate serial rejected, inactive product blocks leaving Draft, quantity precision rejected not truncated — in `tests/Feature/Inventory/OperationGuardsTest.php`
- [ ] T012 [P] [US1] Reconciliation test — every `(product_variant_id, warehouse_id)` balance and the whole movement ledger identical before and after backfill, in-transit totals agreeing under both derivations (R-002) — in `tests/Feature/Inventory/OperationBackfillReconciliationTest.php`
- [ ] T013 [P] [US1] Delivery-note integration test — a confirmed delivery note moves stock exactly once, proving the old path no longer also fires (P-1) — in `tests/Feature/Inventory/OperationDeliveryNoteIntegrationTest.php`
- [ ] T014 [P] [US1] Preview test — `previewEffect()` returns per-line before/after balances and mutates nothing (FR-010) — in `tests/Feature/Inventory/OperationPreviewTest.php`

### Data layer for User Story 1

- [ ] T015 [P] [US1] Create `app/Enums/OperationType.php` with Receipt, Delivery, InternalTransfer and the per-type warehouse requirements from data-model.md §1
- [ ] T016 [P] [US1] Create `app/Enums/OperationStage.php` with the six stages, the legal-transition map and the custody rule helpers from data-model.md §1
- [ ] T017 [US1] Create the `inventory_operations` migration in `database/migrations/` per data-model.md §2, including the `legacy_receipt_id` and `legacy_transfer_id` provenance columns
- [ ] T018 [US1] Create the `inventory_operation_lines` migration in `database/migrations/` per data-model.md §3
- [ ] T019 [P] [US1] Create `app/Models/InventoryOperation.php` with casts, relations, `TracksBlameable`, `SoftDeletes` and the stage predicates
- [ ] T020 [P] [US1] Create `app/Models/InventoryOperationLine.php` with its relations and decimal casts
- [ ] T021 [P] [US1] Create `database/factories/InventoryOperationFactory.php` with states per type and stage
- [ ] T022 [P] [US1] Create `database/factories/InventoryOperationLineFactory.php`
- [ ] T023 [US1] Create `app/Policies/InventoryOperationPolicy.php` gating view, create, edit-draft and the four transitions, and register it in `app/Providers/AppServiceProvider.php`

### Backfill for User Story 1

- [ ] T024 [US1] Create `app/Services/Inventory/OperationBackfillReconciler.php` comparing balances, the movement ledger and in-transit totals before and after backfill (R-002 step 5)
- [ ] T025 [US1] Create the backfill migration in `database/migrations/` copying `inventory_receipts` and `stock_transfers` into the new tables with the status mapping in data-model.md §10 — non-destructive, legacy tables untouched
- [ ] T026 [US1] Run `tests/Feature/Inventory/OperationBackfillReconciliationTest.php` and confirm it is green — command-only gate. **Do not proceed past this task until it passes.**

### Domain services for User Story 1

- [ ] T027 [US1] Create `app/Services/Inventory/InventoryOperationService.php` with `markReady()`, `dispatch()`, `complete()`, `cancel()` and `previewEffect()`, each transactional with a row lock (contracts/inventory-operations.md, P-3)
- [ ] T028 [US1] Implement the reservation path in `markReady()` in `app/Services/Inventory/InventoryOperationService.php` — hold at `Waiting` naming product and shortfall when available is insufficient, changing no balance (FR-004, FR-005)
- [ ] T029 [US1] Implement the custody rule across `dispatch()` and `complete()` in `app/Services/Inventory/InventoryOperationService.php`, writing exactly one movement per balance change and delegating to the retained `app/Services/Inventory/StockTransferService.php` and `app/Services/Inventory/InventoryReceivingService.php` (FR-003, FR-011, A-006)
- [ ] T030 [US1] Implement `cancel()` in `app/Services/Inventory/InventoryOperationService.php` including the compensating movement that restores source on-hand when cancelling from `InTransit` (FR-009)
- [ ] T031 [US1] Re-point `InventoryStock::inTransitQuantity()` in `app/Models/InventoryStock.php` from transfer `status = dispatched` to operation `stage = in_transit`, leaving the grain and every other balance untouched (data-model.md §9)
- [ ] T032 [US1] Audit every existing sales delivery-note stock path under `app/Services/` and route it through `app/Services/Inventory/InventoryOperationService.php` so stock moves once, not twice (**P-1**, FR-013)

### Admin surface for User Story 1

- [X] T033 [US1] Create `app/Filament/Resources/InventoryOperations/InventoryOperationResource.php` with three navigation entries pre-filtered by operation type
- [X] T034 [US1] Create the line editor in `app/Filament/Resources/InventoryOperations/Schemas/OperationLinesRepeater.php` — Product, Package, Demand, Unit, Picked — identical for all three types
- [X] T035 [US1] Create the form schema with General, Operations, Additional and Note tabs in `app/Filament/Resources/InventoryOperations/Schemas/InventoryOperationForm.php`
- [X] T036 [US1] Create the stage bar in `app/Filament/Resources/InventoryOperations/Schemas/OperationStageBar.php` rendering Draft → Waiting → Ready → Done → Canceled, inserting InTransit only for internal transfers (FR-002)
- [X] T037 [US1] Create `app/Filament/Resources/InventoryOperations/Tables/InventoryOperationsTable.php` with Reference, Contact, Scheduled At, Source Document and Stage columns
- [X] T038 [US1] Create the list, create and view pages in `app/Filament/Resources/InventoryOperations/Pages/`, reading balances through `Warehouse::currentOnHand()` and `currentAvailable()` only (P-2)
- [X] T039 [US1] Add the transition actions to `app/Filament/Resources/InventoryOperations/Pages/ViewInventoryOperation.php`, each showing `previewEffect()` output for confirmation before committing (FR-010, SRS §5.1)
- [X] T040 [US1] Retire Returns into the return-filtered Stock Movements log, where return rows actually live; `ManageReturns` redirects there and the movement-type filter preserves the legacy capability (FR-014, A-007).

**Checkpoint**: US1 fully functional. Run the whole `tests/Feature/Inventory` suite plus the
features 003 and 004 acceptance suites before continuing.

---

## Phase 4: User Story 2 — The product record is the single place to understand a product (Priority: P2)

**Goal**: Seven tabs on one product record, with the standalone variants page retired.

**Independent Test**: Open any product, confirm all seven tabs render scoped to it, and confirm
every capability the standalone variants page offered is reachable from the Variants tab.

**Independent of US1** — may run in parallel.

### Tests for User Story 2

- [X] T041 [P] [US2] Tab tests — all seven render, each scoped to the open product, none leaking another product's rows (FR-019, G-1) — in `tests/Feature/Inventory/ProductRecordTabsTest.php`
- [X] T042 [P] [US2] Quantities tab test — on-hand, reserved, available, in-transit and damaged per warehouse plus a total (FR-022) — in `tests/Feature/Inventory/ProductRecordQuantitiesTest.php`
- [X] T043 [P] [US2] Vendors tab test — every supplier reference against one product, price columns hidden without pricing permission (FR-023, FR-024) — in `tests/Feature/Inventory/ProductRecordVendorsTest.php`
- [X] T044 [P] [US2] Redirect test — the old variants route lands on the parent product's Variants tab, not a 404 (FR-021) — in `tests/Feature/Inventory/ProductVariantRedirectTest.php`
- [X] T045 [P] [US2] Query-count test — Quantities and IN/OUT issue no query per row (G-5) — in `tests/Feature/Inventory/ProductRecordQueryCountTest.php`

### Implementation for User Story 2

- [X] T046 [US2] Add `getRecordSubNavigation()` to `app/Filament/Resources/Products/ProductResource.php` and register the tab routes in its `getPages()` (R-003)
- [X] T047 [P] [US2] Create the Attributes relation page in `app/Filament/Resources/Products/Pages/ManageProductAttributes.php`
- [X] T048 [P] [US2] Create the Variants relation page in `app/Filament/Resources/Products/Pages/ManageProductVariants.php`, reusing the table and form already defined inside `app/Filament/Resources/ProductVariants/ProductVariantResource.php` rather than rewriting them
- [X] T049 [P] [US2] Create the Vendors relation page in `app/Filament/Resources/Products/Pages/ManageProductVendors.php` over supplier product references
- [X] T050 [P] [US2] Create the read-only Quantities relation page in `app/Filament/Resources/Products/Pages/ManageProductQuantities.php`, reusing `app/Filament/Resources/StockLevels/Tables/StockLevelsTable.php`
- [X] T051 [P] [US2] Create the read-only IN/OUT relation page in `app/Filament/Resources/Products/Pages/ManageProductMoveLines.php`, reusing `app/Filament/Resources/StockMovements/Tables/StockMovementsTable.php`
- [X] T052 [US2] Ensure none of these relations is also registered in `getRelations()` in `app/Filament/Resources/Products/ProductResource.php`, which would render each twice (R-003)
- [X] T053 [US2] Gate purchase price, unit cost and markup columns behind the pricing-view permission across every page in `app/Filament/Resources/Products/Pages/` (FR-024, SRS §5.3)
- [X] T054 [US2] Add eager loading to `app/Filament/Resources/Products/Pages/ManageProductQuantities.php` and `app/Filament/Resources/Products/Pages/ManageProductMoveLines.php` to satisfy the query-count test
- [X] T055 [US2] Redirect the retired routes in `app/Filament/Resources/ProductVariants/ProductVariantResource.php` to the parent product's Variants tab, keeping the resource class and its table intact (A-006, R-007)

**Checkpoint**: US1 and US2 both work independently.

---

## Phase 5: User Story 3 — Products carry images (Priority: P3)

**Goal**: Product and variant images through the already-installed media library, with no new
Composer dependency.

**Independent Test**: Upload images to a product and a variant, confirm they render in list and
detail views, survive a reload, and can be reordered and removed.

**Independent of US1 and US2** — may run in parallel.

### Tests for User Story 3

- [X] T056 [P] [US3] Upload tests — first image becomes the main image and appears in the product list (FR-026, FR-027) — in `tests/Feature/Inventory/ProductMediaTest.php`
- [X] T057 [P] [US3] Save-hook tests — a save that adds, one that reorders, one that removes, and one that changes nothing, each leaving the collection correct (contracts/packages-and-media.md Part A) — in `tests/Feature/Inventory/ProductMediaSaveHookTest.php`
- [X] T058 [P] [US3] Rejection tests — unsupported mime and oversize upload rejected naming the reason, **existing images intact** (FR-029, G-4) — in `tests/Feature/Inventory/ProductMediaValidationTest.php`
- [X] T059 [P] [US3] Fallback test — a variant with no image shows the parent product's main image (FR-027) — in `tests/Feature/Inventory/ProductVariantMediaFallbackTest.php`

### Implementation for User Story 3

- [X] T060 [P] [US3] Implement `HasMedia` and `InteractsWithMedia` on `app/Models/Product.php`, registering the `images` collection and a `thumb` conversion
- [X] T061 [P] [US3] Implement `HasMedia` and `InteractsWithMedia` on `app/Models/ProductVariant.php` with the same collection plus the parent fallback accessor
- [X] T062 [US3] Add the `FileUpload` field — `image()`, `multiple()`, `reorderable()`, `appendFiles()`, size cap, accepted mimes — to the product form schema in `app/Filament/Resources/Products/Schemas/ProductForm.php`
- [X] T063 [US3] Implement the save hook in `app/Filament/Resources/Products/Pages/ManageProducts.php` translating field state into media-collection add, reorder and remove operations — the one hand-written seam, covered by T057
- [X] T064 [P] [US3] Add the `ImageColumn` rendering the `thumb` conversion to `app/Filament/Resources/Products/Tables/ProductsTable.php` and to the variants table in `app/Filament/Resources/ProductVariants/ProductVariantResource.php`
- [X] T065 [US3] Apply the `RestrictsFileUploadsToSchemaComponents` trait to `app/Filament/Resources/Products/Pages/ManageProducts.php` (contracts/packages-and-media.md, security note)
- [X] T066 [US3] Confirm `git diff --stat composer.json composer.lock` is empty — no dependency was added (R-004)

**Checkpoint**: US1, US2 and US3 all work independently.

---

## Phase 6: User Story 4 — Goods can be grouped into named packages (Priority: P4)

**Goal**: Package types and named package instances that annotate stock and movement lines
without ever holding a balance.

**Independent Test**: Define a package type, create a package in a warehouse, attach it to a stock
line and a movement line, and confirm it appears wherever those lines are listed.

**Independent of US1–US3** — may run in parallel.

### Tests for User Story 4

- [X] T067 [P] [US4] **Balance-invariance test** — balances byte-identical with and without packages attached (FR-034, G-6). The most important test in this phase — in `tests/Feature/Inventory/PackageBalanceInvarianceTest.php`
- [X] T068 [P] [US4] CRUD tests — package type creation makes it selectable; package creation lists name, type and location (FR-031, FR-032) — in `tests/Feature/Inventory/PackageManagementTest.php`
- [X] T069 [P] [US4] Referential tests — deleting a referenced package or type is refused naming the referencing records (FR-035, V-13, V-14) — in `tests/Feature/Inventory/PackageDeletionGuardTest.php`
- [X] T070 [P] [US4] Scoping tests — a package's location must belong to its warehouse, a line's package must belong to the line's warehouse, and moving a package holding goods is refused (V-11, V-15, V-16) — in `tests/Feature/Inventory/PackageScopingTest.php`

### Implementation for User Story 4

- [X] T071 [P] [US4] Create the `package_types` migration in `database/migrations/` per data-model.md §4
- [X] T072 [P] [US4] Create the `packages` migration in `database/migrations/` per data-model.md §5 — **no quantity column** (FR-034)
- [X] T073 [US4] Create the migration in `database/migrations/` adding nullable `package_id` to `inventory_adjustment_items`, `stock_transfer_items` and `inventory_movements` per data-model.md §6
- [X] T074 [P] [US4] Create `app/Models/PackageType.php` with its relations and soft deletes
- [X] T075 [P] [US4] Create `app/Models/Package.php` with warehouse and location relations, and the scoping validators
- [X] T076 [P] [US4] Create `database/factories/PackageTypeFactory.php` and `database/factories/PackageFactory.php`
- [X] T077 [P] [US4] Create `app/Filament/Resources/PackageTypes/PackageTypeResource.php` with its table and pages, for the Configurations menu
- [X] T078 [P] [US4] Create `app/Filament/Resources/Packages/PackageResource.php` with its table and pages, for the Products menu
- [X] T079 [US4] Show Package on line-grained operation, adjustment and movement surfaces. Stock Levels remain warehouse aggregates with no false package field; its Packages action drills into the matching line-grained Stock Movements view, which shows the Package column.
- [X] T080 [US4] Implement the deletion guards and the warehouse-move guard on `app/Models/Package.php` and `app/Models/PackageType.php`

**Checkpoint**: All four functional stories work independently.

---

## Phase 7: User Story 5 — Four menus instead of fifteen entries (Priority: P5)

**Goal**: The inventory area exposes exactly four menus, with every prior capability still
reachable.

**Independent Test**: Confirm four top-level menus, every capability within two clicks, and no
orphaned retired page.

**⚠️ Must run last** — it groups pages the earlier stories create.

### Tests for User Story 5

- [X] T081 [P] [US5] Structure test — exactly four inventory menus, every prior capability within two clicks (FR-036, FR-037) — in `tests/Feature/Inventory/InventoryNavigationTest.php`
- [X] T082 [P] [US5] Redirect test — retired Reservations and Returns links land on the filter or tab now hosting them (FR-038, R-007) — in `tests/Feature/Inventory/RetiredRouteRedirectTest.php`
- [X] T083 [P] [US5] Permission test — forbidden entries absent, no empty menu rendered (FR-039) — in `tests/Feature/Inventory/InventoryNavigationPermissionTest.php`
- [X] T084 [P] [US5] Localisation test — navigation and operation screens render right-to-left in Arabic with translated labels (FR-040, SRS §5.1) — in `tests/Feature/Inventory/InventoryLocalisationTest.php`

### Implementation for User Story 5

- [X] T085 [US5] Restructure the inventory group in `app/Filament/AdminModuleRegistry.php` into the four sections in [research.md R-006](./research.md), keeping the class free of queries and permission rules
- [X] T086 [US5] Register the Operations entries in `app/Filament/AdminModuleRegistry.php` — Receipts, Deliveries, Internal Transfers, Quantity Adjustments, Scraps
- [X] T087 [US5] Register the Products menu in `app/Filament/AdminModuleRegistry.php` — Products, Packages, Lots / Serial Numbers
- [X] T088 [US5] Register the Reporting and Configurations menus in `app/Filament/AdminModuleRegistry.php` per R-006
- [X] T089 [US5] Remove the Product Variants, Reservations and Returns entries from `app/Filament/AdminModuleRegistry.php`, leaving their resources and data in place (A-006)
- [X] T090 [US5] Add the reservations filter that replaces the retired standalone page to `app/Filament/Resources/StockLevels/Tables/StockLevelsTable.php` (FR-038)

**Checkpoint**: All five stories complete.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [X] T091 Retain `legacy_receipt_id` and `legacy_transfer_id` as immutable provenance: the idempotent backfill and reconciler depend on them, and the feature is additive (A-006). `OperationBackfillReconciliationTest` verifies the contract; reconciliation against production-shaped data is a deployment gate, not a destructive follow-up migration (data-model.md §10).
- [X] T092 Retire `app/Filament/Resources/InventoryReceipts/InventoryReceiptResource.php` and `app/Filament/Resources/Transfers/TransferResource.php` from navigation once features 003 and 004 suites pass against the new tables, keeping the classes (A-006)
- [ ] T093 [P] Verify no N+1 across the operation list, product tabs and package views — command-only gate, no file edit
- [X] T094 [P] Confirm `tests/Unit/ArchTest.php` passes with **no new allowlist exceptions** — a new exception means a write surface bypassed the domain services (P-2)
- [ ] T095 Run `vendor/bin/pint --dirty --format agent`
- [ ] T096 Run `vendor/bin/phpstan analyse` and remove any entries this feature made obsolete from `phpstan-baseline.neon` — the baseline may only shrink
- [ ] T097 Walk every scenario in [quickstart.md](./quickstart.md) manually, including the Arabic RTL pass
- [ ] T098 Run the full `composer test` gate and compare against the T002 baseline — no suite may regress (SC-007). Command-only gate, no file edit
- [ ] T099 Report changed files, database changes and tests added, per Constitution Principle VI item 8 — command-only gate, no file edit

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: no dependencies
- **Foundational (Phase 2)**: depends on Setup — blocks all user stories
- **US1 (Phase 3)**: depends on Foundational
- **US2, US3, US4 (Phases 4–6)**: depend on Foundational only — independent of US1 and of each other
- **US5 (Phase 7)**: depends on Phases 3–6, because it groups the pages they create
- **Polish (Phase 8)**: depends on all stories

### Critical path

```
Setup → Foundational → US1 (T024→T025→T026 gate) → US5 → Polish
                    ↘ US2 ↗
                    ↘ US3 ↗
                    ↘ US4 ↗
```

### Hard gates

| Gate | Task | Consequence of skipping |
|---|---|---|
| Backfill reconciliation | T026 | Silent stock drift against a NON-NEGOTIABLE principle |
| Single delivery stock path | T032 | Stock moves twice on every delivery |
| Package balance invariance | T067 | A second source of quantity truth |
| No new arch exception | T094 | Principle III weakened rather than satisfied |

### Within each story

Tests first and failing → enums and migrations → models and factories → policy → services →
Filament surface → integration.

---

## Parallel Execution Examples

### User Story 1 tests — seven files, all independent

```bash
php artisan test --compact tests/Feature/Inventory/OperationLifecycleTest.php tests/Feature/Inventory/OperationStockEffectTest.php tests/Feature/Inventory/OperationInTransitTest.php tests/Feature/Inventory/OperationGuardsTest.php tests/Feature/Inventory/OperationBackfillReconciliationTest.php tests/Feature/Inventory/OperationDeliveryNoteIntegrationTest.php tests/Feature/Inventory/OperationPreviewTest.php
```

### User Story 2 relation pages — five separate files

T047, T048, T049, T050 and T051 touch different files and can be written concurrently once T046
registers the routes.

### Across stories

With three developers after Phase 2: one on US1 (longest, highest risk), one on US2 then US5, one
on US3 then US4.

---

## Implementation Strategy

### MVP — User Story 1 only

1. Phase 1 Setup
2. Phase 2 Foundational
3. Phase 3 US1, stopping at the T026 reconciliation gate before proceeding
4. Validate independently: all three operation types walk their lifecycle, balances reconcile
5. This alone resolves the "hard to understand" complaint and is demonstrable

### Incremental delivery

Foundation → US1 (MVP, demo) → US2 (demo) → US3 (demo) → US4 (demo) → US5 (the visible payoff) →
Polish. Each increment leaves the system releasable.

### Notes

- `[P]` means different files with no incomplete dependency
- Commit after each task or logical group
- Verify tests fail before implementing
- Every stage transition touching stock runs in a transaction with a row lock (P-3)
- Nothing is deleted: no service, no table, no permission (A-006)
