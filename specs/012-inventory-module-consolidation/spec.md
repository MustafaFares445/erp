# Feature Specification: Inventory Module Consolidation and UX Clarity

**Branch**: `feature/filament-inventory-dashboard` | **Date**: 2026-07-25 | **Status**: Draft

## Problem Statement

The Inventory module grew across eleven sequential feature phases (001–011). Each phase
registered its own top-level Filament resource without a consolidation pass. The result is
a module that is functionally complete but structurally confusing to the client:

- **24 navigation destinations** describe what is conceptually **four questions**: what do we
  sell, what do we hold and where, what moved, and what needs attention.
- **Six of those destinations live outside the Inventory module** (Reports, System, Purchasing),
  so "inventory" has no single home.
- **Three separate pages answer one question.** Stock Levels, Inventory Lots, and Serialized
  Devices are all "where is my stock", differing only in granularity. All three are keyed on
  `(product_variant_id, warehouse_id)`.
- **Four low-frequency setup pages** (Categories, Brands, Attributes, Units) each consume a
  permanent sidebar slot.
- **Warehouse locations are a dead end.** `warehouse_locations` has a table, model, factory,
  and relation manager, but `warehouse_location_id` appears **nowhere** in migrations, models,
  or services. Bin-level stock was named in feature 002's title and never wired to stock.

## Observed Evidence

Captured 2026-07-25 against `http://127.0.0.1:8000/admin` at 1440×900, signed in as
`admin@ierp.com`, with warehouse/stock data created via factories.

### Information architecture

| ID | Observation | Evidence |
|----|-------------|----------|
| IA-1 | Inventory sidebar renders 18 flat items with no sub-grouping; the list extends past the viewport fold. | Stock Levels, Warehouses screenshots |
| IA-2 | Module bar wraps to two rows at 1440px (9 modules), consuming ~90px of vertical space above every page. | All screenshots |
| IA-3 | Sidebar, main content, and window each own a scrollbar — three nested scroll regions at desktop width. | Warehouses screenshot |
| IA-4 | The Dashboard renders an **empty sidebar** (only "Dashboard"), because navigation is scoped to the active module and the Dashboard belongs to none. Users land with no visible way into any module except the top bar. | Dashboard screenshot |
| IA-5 | Inventory Reports sits in the **Reports** group, Inventory Settings in **System**, and four pricing pages in **Purchasing**. | `AdminModuleRegistry::groups()` |

### Naming and labelling

| ID | Observation | Evidence |
|----|-------------|----------|
| NM-1 | Sidebar says "Stock Levels"; page title and breadcrumb say "Inventory Stocks". Two names for one screen. | Stock Levels screenshot |
| NM-2 | Breadcrumbs terminate in "List" — a framework term with no client meaning. | Stock Levels, Warehouses |
| NM-3 | Column label "Is active" is a raw attribute name; no `->label()` set. | `WarehousesTable.php:28` |
| NM-4 | Column label "Stock Rows" exposes a database row count as client-facing language. | `WarehousesTable.php:33` |
| NM-5 | Empty states reuse the model label ("No inventory stocks") including inside the **Low stock** widget, where the correct message is "No items below reorder level". | Dashboard, Stock Levels |

### Icons

| ID | Observation | Evidence |
|----|-------------|----------|
| IC-1 | `Heroicon::OutlinedRectangleStack` is used by **four** resources: Warehouses, Inventory Lots, Inventory Alerts, Serialized Devices — visually indistinguishable in the sidebar. | Resource classes |
| IC-2 | `Heroicon::OutlinedAdjustmentsHorizontal` is used by both Adjustments and Product Attributes. | Resource classes |

### Tables and widgets

| ID | Observation | Evidence |
|----|-------------|----------|
| TB-1 | Stock Levels renders 11+ columns; columns from "Damaged" rightward are clipped at 1440px with no horizontal affordance. | Stock Levels screenshot |
| TB-2 | `WarehousesTable` declares no `defaultSort`, so row order is non-deterministic. | `WarehousesTable.php` |
| TB-3 | `DeleteAction` is registered unconditionally but hidden by policy when a warehouse holds stock. The client sees Delete on some rows and not others with no explanation. | `WarehousesTable.php:48` |
| TB-4 | Warehouses list shows a malformed final row (collapsed columns) and renders fewer rows than its own paginator claims ("Showing 1 to 10 of 29"). Root cause unconfirmed — requires reproduction. | Warehouses screenshots |
| WD-1 | "Stock value by warehouse" renders an axis frame with no data plus 11 overlapping 45°-rotated warehouse labels. No category cap or aggregation. | Dashboard screenshot |
| WD-2 | Dashboard KPI tiles show only *draft document counts* (Draft adjustments, Draft transfers) — no headline inventory figures (stock value, SKUs, warehouses, open alerts). | Dashboard screenshot |
| WD-3 | Widget grid is ragged: cards of mismatched height leave a large dead gap before the next row. | Dashboard screenshot |
| WD-4 | No widget states its period or as-of time. | Dashboard screenshot |

### Interaction model

| ID | Observation | Evidence |
|----|-------------|----------|
| IX-1 | Three different page patterns coexist with no rule: full CRUD pages (Adjustments, Transfers, Warehouses), List+View only (8 resources), and single-page `Manage*` with modals (15 resources). | `app/Filament/Resources/*/Pages/` |

### Database and data layer

| ID | Observation | Evidence |
|----|-------------|----------|
| DB-1 | `warehouse_location_id` appears in **no** migration, model, or service. Locations can be created but nothing references them; bin-level stock does not exist. | Repository-wide search |
| DB-2 | **12 factories are unimplemented stubs** with an empty `definition()`: Brand, InventoryExport, PriceFloorOverride, PriceHistory, ProductAttribute, ProductAttributeValue, ProductCategory, ProductVariantAttributeValue, StockReservation, Supplier, SupplierProductReference, Unit. | `database/factories/` |
| DB-3 | `stock_reservations.quantity`, `source_type`, `source_id` are `NOT NULL` with no default, so `StockReservation::factory()` throws. Reservations are service-created only; the resource is read-only with no form. | Schema + `StockReservationFactory.php` |
| DB-4 | `InventoryDemoSeeder` → `DentalCatalogSeeder` creates catalog master data only. It contains `removeDemoDocuments`/`removeDemoStock`/`removeDemoMasterData` cleanup paths but **never creates a warehouse, stock row, or movement**. There is no seeded dataset for a client walkthrough of the warehouse side. | `DentalCatalogSeeder.php:160–199`; post-seed counts: warehouses 0, stocks 0 |
| DB-5 | `inventory_stocks.available_quantity` is a plain stored column. The invariant `on_hand − reserved − damaged` is enforced only in `InventoryBalanceService:228` with no database guard or reconciliation check. Reports and widgets read the stored value. | Schema + service |

## Target Information Architecture

Four client questions become six sidebar sections and 14 leaf destinations, down from 24,
with **every existing capability retained and directly linkable**.

```
Overview                       ← inventory KPIs + alert triage

Catalog
  Products                     ← view tabs: Variants | Pricing | Suppliers | Stock
  Catalog setup                ← tabs: Categories | Brands | Attributes | Units
  Pricing                      ← tabs: Tiers | Customer tiers | Price history | Floor overrides

Stock
  Stock on hand                ← tabs: By SKU | By warehouse | By lot & expiry | By device
  Stock history                ← movement ledger
  Warehouses                   ← view tabs: Locations | Stock | Activity

Operations
  Receipts                     ← goods in
  Transfers                    ← between warehouses
  Adjustments                  ← corrections
  Returns                      ← goods back
  Reservations                 ← holds (read-only)

Insights
  Alerts
  Reports
  Data transfer                ← tabs: Imports | Exports

Settings
```

### Consolidation decisions

| Current pages | Becomes | Rationale |
|---|---|---|
| Stock Levels + Inventory Lots + Serialized Devices | **Stock on hand**, three lenses | One question, one page. All keyed on the same pair of foreign keys. |
| Categories + Brands + Attributes + Units | **Catalog setup**, four tabs | Low-frequency configuration should not hold four permanent sidebar slots. |
| Import Runs + Exports | **Data transfer**, two tabs | Both are file-based data movement with run history. |
| Product Variants (top level) | Tab inside **Products** | A variant is not a sibling of its product. Remains globally searchable. |
| 4 pricing pages in Purchasing | **Pricing**, four tabs under Catalog | Product pricing belongs with the product, not with purchasing. |
| Reports (Reports group), Settings (System group) | Moved under Inventory | Inventory gains a single home. |
| Receipts, Transfers, Adjustments, Returns, Reservations | **Grouped**, not merged | These are stateful documents with distinct workflows. Grouping cuts visible items without burying real flows. |

## Requirements

### Phase A — Structure and data correctness (must precede UX work)

- **FR-A1** Every Inventory capability reachable today MUST remain reachable, at a stable URL,
  after consolidation. No feature is removed.
- **FR-A2** The Inventory sidebar MUST present at most 6 collapsed top-level entries.
- **FR-A3** Each resource MUST have a unique navigation icon within its module.
- **FR-A4** Navigation label, page heading, breadcrumb, and browser title MUST agree for every
  page. Breadcrumbs MUST NOT end in "List".
- **FR-A5** Every table column MUST declare an explicit client-facing `->label()`. No raw
  attribute names, no database vocabulary.
- **FR-A6** Every resource MUST follow one documented page pattern, selected by a stated rule
  (stateful document → full pages; reference data → tabbed manage page; read-only → list+view).
- **FR-A7** `warehouse_location_id` MUST be wired end to end — stock, movements, receipts,
  transfers, adjustments — or locations MUST be explicitly descoped and their UI removed.
  Partial implementation is not acceptable. *(See Decision D-1.)*
- **FR-A8** All 12 stub factories MUST produce valid persistable models.
- **FR-A9** `StockReservation::factory()` MUST create a valid reservation, including its
  polymorphic source.
- **FR-A10** A demo seeder MUST produce a complete, believable warehouse dataset: warehouses,
  locations, stock across SKUs, movements, and at least one document of each type — sufficient
  for a client walkthrough.
- **FR-A11** A reconciliation check MUST detect drift between `available_quantity` and
  `on_hand − reserved − damaged`, covered by a test.
- **FR-A12** The Warehouses list row/pagination defect (TB-4) MUST be reproduced, fixed, and
  covered by a regression test.

### Phase B — UX optimisation

- **FR-B1** The landing page MUST expose primary navigation. A page with an empty sidebar is
  not acceptable.
- **FR-B2** Overview MUST lead with inventory KPIs (stock value, SKUs stocked, warehouses,
  open alerts), not draft-document counts alone.
- **FR-B3** Every widget MUST state its period or as-of time.
- **FR-B4** Charts MUST cap or aggregate categories so axis labels stay legible, and MUST show
  a purposeful empty state instead of an empty axis frame.
- **FR-B5** Wide tables MUST fit the default 1440px viewport by default, with secondary columns
  toggleable, and MUST NOT clip content without a scroll affordance.
- **FR-B6** Empty states MUST describe the situation and offer the next action, not restate the
  model name.
- **FR-B7** Actions hidden by policy MUST either be absent consistently or shown disabled with
  a reason. Silent per-row inconsistency is not acceptable.
- **FR-B8** Every table MUST declare a deterministic default sort.
- **FR-B9** The module bar MUST NOT wrap at 1440px.
- **FR-B10** The layout MUST NOT nest three scroll regions at desktop width.
- **FR-B11** Numeric columns MUST use tabular figures and consistent decimal precision.

## Success Criteria

- **SC-1** Inventory navigation: 24 destinations → 14, with 6 collapsed top-level entries.
- **SC-2** A first-time client user can answer "what do we have and where" from one page.
- **SC-3** No duplicate icons, no label/title mismatches, no "List" breadcrumbs in the module.
- **SC-4** `php artisan migrate:fresh --seed` yields a populated warehouse dataset.
- **SC-5** `composer test` passes; the PHPStan baseline does not grow.
- **SC-6** Every pre-consolidation URL either still resolves or redirects to its new home.

## Decisions Required

- **D-1 — Warehouse locations.** The request states warehouse features should ship "without
  missing any feature", which reads as: implement bin-level stock properly (FR-A7, option A).
  This is the single largest item in the plan — it adds a foreign key and UI to stock, movements,
  and all four document types. The alternative (option B) is to descope locations and delete the
  table, model, factory, and relation manager. **Recommendation: option A**, phased so the
  location column is nullable first and made required only once backfilled.

## Out of Scope

- Non-Inventory modules' navigation and dashboards (constitution: Filament exception covers
  Inventory only, per ADR 0001).
- New reports, new export formats, currency conversion.
- Dependency changes, public routes, or a customer-facing API.
