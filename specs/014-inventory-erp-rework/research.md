# Phase 0 Research: Inventory Module ERP-Pattern Rework

**Feature**: `specs/014-inventory-erp-rework` | **Date**: 2026-07-27

All Technical Context unknowns are resolved below. No `NEEDS CLARIFICATION` remains.

---

## R-001: How a uniform lifecycle coexists with in-transit visibility

**Decision**: One operation document per physical movement. Lifecycle is
`Draft → Waiting → Ready → Done → Canceled`, with internal transfers passing through an
additional `InTransit` stage between `Ready` and `Done`.

The governing invariant, which holds for every type without exception:

> A warehouse's on-hand balance changes at the moment that warehouse's custody of the goods changes.

| Operation type | Source loses on-hand at | Destination gains on-hand at |
|---|---|---|
| Receipt | n/a (external supplier) | `Done` |
| Delivery | `Done` | n/a (external customer) |
| Internal Transfer | `InTransit` | `Done` |

In-transit quantity is therefore what has left the source but not reached the destination —
counted against neither warehouse. This is exactly SRS §3.12.

**Rationale**:

1. The reference ERP (Odoo/Aureus lineage) does not have a single-document in-transit state
   either. It achieves in-transit with **two chained documents** moving stock through a physical
   *transit location*. That mechanism depends on location being the balance grain. The
   clarification session ruled location-grain out (A-001), so adopting the two-document shape
   would import its cost without the mechanism that justifies it.
2. Splitting the existing `stock_transfers` rows into pairs is a **balance-affecting data
   migration** against Constitution Principle III, which is NON-NEGOTIABLE. Renaming lifecycle
   stages is not balance-affecting. Prefer the migration that cannot silently drift stock.
3. `StockTransferService::dispatch()` / `receive()`, `StockTransferPolicy::receive()`, and
   `InventoryStock::inTransitQuantity()` keep their shape and their spec-004 tests. A paired
   model rewrites all of them.
4. The feature exists to reduce concepts. One document per physical movement is fewer concepts
   than two linked documents.

**Alternatives considered**:

- *Linked pair of operations* (the spec's original A-002). Rejected per the four reasons above.
- *Collapse to five stages, decrement the source at `Ready`*. Rejected: it makes `Ready` mean
  something different for transfers than for receipts, which is the per-type divergence the
  feature exists to remove, and it breaks the custody invariant.
- *Five stages with no in-transit at all*. Rejected: violates SRS §3.12 outright.

**Existing behaviour this preserves**: `InventoryStock::inTransitQuantity()` currently derives
in-transit by summing `stock_transfer_items` where the parent transfer's status is `dispatched`.
Under this decision the derivation is unchanged apart from the status value being renamed.

---

## R-002: Migrating three documents into one

**Decision**: Introduce `inventory_operations` and `inventory_operation_lines` as new tables, then
backfill from the three existing document tables. Keep the legacy tables in place, read-only,
until the acceptance suites of features 003 and 004 pass green against the new tables.

**Status value mapping**:

| Legacy | Legacy value | New stage |
|---|---|---|
| `ReceiptStatus` | `draft` | `Draft` |
| `ReceiptStatus` | `confirmed` | `Done` |
| `TransferStatus` | `draft` | `Draft` |
| `TransferStatus` | `dispatched` | `InTransit` |
| `TransferStatus` | `received` | `Done` |
| `AdjustmentStatus` | `draft` | `Draft` |
| `AdjustmentStatus` | `confirmed` | `Done` |

`Waiting` and `Canceled` are new; no historical row maps to them.

**Rationale**: Adjustments and scraps stay on their own tables — the clarification put them in a
separate section from the three transfer types, and they have no source/destination pair. Only
receipts and transfers fold into `inventory_operations`. Backfill rather than rename because the
two legacy shapes differ (receipts have a supplier, transfers have two warehouses), so a single
`ALTER` cannot reconcile them.

**Alternatives considered**:

- *Rename `stock_transfers` to `inventory_operations` and merge receipts into it.* Rejected: the
  in-place rename gives no rollback point, and the constitution requires inventory operations to
  be reversible.
- *Keep three tables behind one presentation layer.* Rejected: it delivers the ERP look without
  the underlying simplification, which is the stated goal.

**Risk**: This is the highest-risk item in the feature. Movement rows must reconcile exactly
before and after. Mitigation is a reconciliation check comparing every
`product_variant_id + warehouse_id` balance and the full movement ledger pre- and post-backfill,
run as a test rather than a script.

---

## R-003: Tabbed product record

**Decision**: Use Filament v5 resource sub-navigation — `getRecordSubNavigation()` on
`ProductResource`, returning `$page->generateNavigationItems([...])` over a `ViewRecord`, an
`EditRecord`, and a set of `ManageRelatedRecords` pages registered in `getPages()` with
`/{record}/...` routes.

Tab-to-page mapping:

| Tab | Page type | Backing relation |
|---|---|---|
| View | `ViewRecord` | — |
| Edit | `EditRecord` | — (media lives here) |
| Attributes | `ManageRelatedRecords` | product attribute values |
| Variants | `ManageRelatedRecords` | variants |
| Vendors | `ManageRelatedRecords` | supplier product references |
| Quantities | `ManageRelatedRecords` | inventory stocks |
| IN/OUT | `ManageRelatedRecords` | inventory movements |

**Rationale**: This is Filament's native, documented mechanism for exactly this pattern, and it
renders as the horizontal button bar the reference screenshots show. `ManageRelatedRecords` pages
take the same `table()` and `form()` as relation managers, so existing table definitions
(`StockLevelsTable`, `StockMovementsTable`) can be reused rather than rewritten.

**Alternatives considered**:

- *Relation managers on `ViewProduct`.* Rejected: relation managers render stacked below the
  record rather than as switchable tabs, so it would not match the reference layout.
- *A custom page with a Tabs schema component.* Rejected: loses per-tab routing and deep links.

**Note**: `ManageRelatedRecords` replaces the need for `make:filament-relation-manager` on these
relations; they must not also be registered in `getRelations()`.

---

## R-004: Media handling without a new dependency

**Decision**: Use Filament's native `FileUpload` field plus `ImageColumn`, wired to
`spatie/laravel-medialibrary` in the model layer via `HasMedia` / `InteractsWithMedia` and a
registered media collection. **Do not add `filament/spatie-laravel-media-library-plugin`.**

**Rationale**: `spatie/laravel-medialibrary ^11.23` is already a dependency and
`config/media-library.php` is already published, but **no model currently implements `HasMedia`** —
media is entirely unused. The Filament media-library *plugin* is a separate Composer package and
is **not installed** (`vendor/filament/` contains only the core packages). Both `CLAUDE.md` and
Constitution Principle II forbid changing dependencies without approval. Native `FileUpload`
supports `image()`, `multiple()`, `reorderable()`, `appendFiles()`, `imageEditor()`, `maxSize()`
and dimension rules — everything FR-026 to FR-029 require — so the plugin buys convenience, not
capability.

**Alternatives considered**:

- *Add the Filament media-library plugin.* Not rejected on merit; it is simply a dependency
  change requiring project-owner approval, which has not been sought. Worth raising separately —
  it would remove the model-layer wiring described below.
- *Plain `FileUpload` writing to disk with no media library.* Rejected outright: Constitution
  Principle IV mandates Media Library for product images and forbids per-feature file tables.

**Consequence for the plan**: each product/variant form needs an explicit save hook translating
`FileUpload` state into media collection operations. This is the one place the plan trades a
little code for zero dependency change, and it must be covered by tests.

**Security note**: Filament's docs flag that `InteractsWithSchemas` exposes Livewire upload RPC
methods on every page using it. Pages carrying uploads should use the
`RestrictsFileUploadsToSchemaComponents` trait.

---

## R-005: Package semantics under warehouse grain

**Decision**: Two new tables — `package_types` (configuration) and `packages` (named instances,
each belonging to a warehouse and optionally a `warehouse_location`). A nullable `package_id`
foreign key is added to the stock/movement line tables. `packages` holds **no quantity column**.

**Rationale**: A-001 fixes the balance grain at product variant + warehouse. Giving packages a
quantity would create a second place quantity lives, which is the drift risk Principle III
exists to prevent. Nullable foreign keys make the whole feature additive — every existing query
and balance is untouched, mirroring how `warehouse_location_id` was already introduced.

**Alternatives considered**:

- *Packages hold balances.* Rejected: contradicts A-001 and re-opens Principle III.
- *Packaging as a unit-of-measure multiple only.* This is the strict SRS §3.5 reading and is
  cheaper, but the clarification session explicitly chose types plus instances.

---

## R-006: Navigation consolidation target

**Decision**: Collapse the inventory group's fifteen registry entries into four sections in
`AdminModuleRegistry::groups()`.

| Menu | Contents |
|---|---|
| Operations | Receipts, Deliveries, Internal Transfers, Quantity Adjustments, Scraps |
| Products | Products, Packages, Lots / Serial Numbers |
| Reporting | Stock Levels, Stock Movements, Inventory Reports, Alerts |
| Configurations | Warehouses (with Locations), Package Types, Catalog Setup, Imports, Inventory Settings |

Retired as standalone entries: Product Variants (→ product tab), Reservations (→ filter plus
product Quantities tab), Returns (→ filter on Receipts). Their resources, services and data
survive; only the navigation entry and top-level page go.

**Rationale**: `AdminModuleRegistry` already models `sections` within a group and already resolves
links defensively via `resolveLink()`, so this is a data change to `groups()` plus route
redirects, not new navigation machinery. The registry is documented as free of queries and
permission rules, and that property must be preserved.

**Alternatives considered**:

- *Filament clusters.* Rejected: the panel already has a bespoke module/top-bar pattern built on
  `AdminModuleRegistry`, and introducing clusters alongside it would mean two navigation systems.

---

## R-007: Preserving retired routes

**Decision**: Keep the retired pages' routes registered and redirect them to their replacement —
the variants index to the parent product's Variants tab, reservations and returns to the filtered
list that now hosts them.

**Rationale**: FR-021 and acceptance scenario 5.3 require old links to land somewhere useful
rather than 404. Bookmarks and any saved report links must keep working.

---

## R-008: Testing approach

**Decision**: Pest feature tests per user story, plus a dedicated reconciliation test for R-002.
Run with `php artisan test --compact --filter=...` scoped to the affected suites during
development, and the full `composer test` gate before completion.

Existing coverage that must stay green, unchanged: `tests/Feature/Inventory/` (including the
untracked `InventoryNavigationTest` and `WarehouseLocationTrackingTest`), and
`tests/Unit/ArchTest.php`, which enforces that Filament resource namespaces do not write to
`InventoryStock` directly. That architecture rule constrains the new operation resources: they
must go through domain services, exactly as `Warehouse::currentOnHand()` exists to let
`Adjustments` read balances without touching `InventoryStock`.

**Rationale**: The architecture test is the mechanism that keeps Principle III enforceable in
code rather than in prose. New resources must be written to satisfy it from the start rather than
being excepted into it.
