# Implementation Plan: Inventory Module Consolidation and UX Clarity

**Branch**: `feature/filament-inventory-dashboard` | **Date**: 2026-07-25 | **Spec**: [spec.md](spec.md)

## Summary

Collapse 24 Inventory navigation destinations into 14 under 6 collapsed sidebar sections without
removing a single capability. Fix the structural and data defects first — dead-end warehouse
locations, 12 stub factories, a demo seeder that seeds no warehouse data, duplicate icons,
label/title drift — then optimise the presentation layer. Structure precedes polish because
every UX fix lands on a page whose identity is still moving.

## Technical Context

**Language/Version**: PHP 8.4

**Primary Dependencies**: Laravel 13, Filament 5, Livewire 3, Pest 4, Larastan 3

**Storage**: Existing inventory, catalog, pricing, warehouse, document, and audit tables; one new
nullable foreign key (`warehouse_location_id`) per Decision D-1 option A

**Testing**: Pest feature tests for Filament resources, navigation, policies, factories, seeders,
plus `tests/Unit/ArchTest.php` for the page-pattern rule

**Target Platform**: System Administrator Filament panel (`admin`), desktop-first at 1440px

**Constraints**: No new dependencies, no public routes, no API, no destructive data migration.
PHPStan baseline may only shrink. Every pre-existing URL keeps working or redirects.

## Constitution Check

- **Specification-first**: PASS. Target IA, consolidation table, and database decisions are fixed
  in [spec.md](spec.md) before any code moves. Database design (D-1) is settled before implementation,
  as Principle I requires for persisted-data changes.
- **Modular monolith**: PASS. Work is confined to `app/Filament`, `app/Models`, `database/`, and
  the Inventory service namespace. No new top-level directory.
- **Inventory integrity**: PASS. Stock write paths are untouched in Phase A1–A3. The location
  foreign key (A4) is additive and nullable; `InventoryBalanceService` remains the single writer
  of `available_quantity`, and A6 adds a drift check rather than a second writer.
- **Unified access**: PASS. Consolidated tabbed pages recheck each merged resource's existing
  permission at the tab boundary, so a user who cannot see Devices today cannot see the Devices
  lens tomorrow.
- **Engineering discipline**: PASS. Every requirement in the spec is expressed as an assertable
  test — navigation counts, icon uniqueness, label agreement, factory validity, seeder output,
  URL survival.
- **Scope boundary**: PASS. The approved Inventory Filament exception (ADR 0001) applies. Other
  modules' navigation is explicitly out of scope.

## Project Structure

```text
app/Filament/
|-- AdminModuleRegistry.php              # sections + sub-groups; single source of IA
|-- Clusters/Inventory/                  # NEW: Catalog, Stock, Operations, Insights
|-- Pages/
|   |-- Dashboard.php                    # -> Inventory Overview, KPI tiles
|   `-- InventoryOverview.php            # NEW
|-- Resources/
|   |-- Products/                        # + Variants relation manager (absorbs top-level page)
|   |-- CatalogSetup/                    # NEW: tabs for Categories | Brands | Attributes | Units
|   |-- StockOnHand/                     # NEW: absorbs StockLevels + InventoryLots + Serialized
|   |-- StockHistory/                    # renamed from StockMovements
|   |-- Warehouses/                      # + Locations | Stock | Activity view tabs
|   |-- DataTransfer/                    # NEW: absorbs ImportRuns + Exports
|   `-- Pricing/                         # NEW: absorbs 4 pricing resources from Purchasing
`-- Widgets/                             # KPI + period-aware, capped chart categories

database/
|-- factories/                           # 12 stubs implemented
|-- migrations/                          # + warehouse_location_id (nullable, additive)
`-- seeders/InventoryDemoSeeder.php      # real warehouse dataset

tests/Feature/Inventory/
|-- InventoryNavigationTest.php          # NEW: counts, icon uniqueness, label agreement
|-- InventoryUrlCompatibilityTest.php    # NEW: every old URL resolves or redirects
|-- StockOnHandLensTest.php              # NEW: per-lens permissions
|-- WarehouseLocationStockTest.php       # NEW
`-- InventoryDemoSeederTest.php          # NEW
tests/Unit/ArchTest.php                  # + page-pattern rule
```

**Structure Decision (revised 2026-07-26 during implementation)**: Not Filament clusters.
`AdminPanelServiceProvider::navigation()` already replaces Filament's default navigation
entirely with a hand-built one, and it calls `NavigationBuilder::items()` exclusively — reading
Filament's own source (`vendor/filament/filament/src/Navigation/NavigationBuilder.php`) confirms
`items()` always collapses everything into one anonymous, unlabeled group regardless of each
item's own `.group()` value. That — not a missing feature — is the direct cause of IA-1's flat
18-item sidebar. Filament Clusters wrap `Resource`/`Page` classes specifically; layering them in
would require teaching `AdminModuleRegistry::resolveLink()`/`isAccessDenied()` (which type-check
against `Resource::class`/`Page::class`) about a third type, for no benefit over the simpler fix.

The actual fix: give `AdminModuleRegistry`'s inventory items an optional `section` key, and change
`AdminPanelServiceProvider::navigation()` to call `NavigationBuilder::groups()` with real
collapsible `NavigationGroup` objects — one per section — instead of one flat `items()` call.
This is native, already-tested Filament API, additive to `AdminModuleRegistry`'s existing shape
(every current test uses `toHaveKeys()`, not exact-key assertions, so a new optional key is
non-breaking), and needs no resource-type surgery. `AdminModuleRegistry` stays the single source
of truth.

## Phases

Phase A must complete before Phase B starts. A1–A3 are independent of Decision D-1 and can begin
immediately.

### Phase A — Structure and data correctness

| # | Work | Requirements | Depends on |
|---|------|--------------|------------|
| **A1** | **Naming and icon pass.** Unique icon per resource (IC-1, IC-2). Explicit `->label()` on every column. Align navigation label, heading, breadcrumb, title; drop "List". Deterministic `defaultSort` per table. | FR-A3, A4, A5, B8 | — |
| **A2** | **Factory and seeder repair.** Implement the 12 stub factories. Fix `StockReservationFactory` including its polymorphic source. Rewrite `InventoryDemoSeeder` to produce warehouses, locations, stock, movements, and one document of each type. | FR-A8, A9, A10 | — |
| **A3** | **Integrity guards.** Reconciliation check for `available_quantity` drift with a test. Reproduce and fix the Warehouses row/pagination defect (TB-4). | FR-A11, A12 | A2 (needs factories) |
| **A4** | **Warehouse locations, end to end.** Add nullable `warehouse_location_id` to `inventory_stocks` and `inventory_movements`; extend receipts, transfers, adjustments to carry it; surface it in the Warehouse view and stock lenses. Backfill, then tighten. | FR-A7 | D-1, A2 |
| **A5** | **Page-pattern rule.** Document the rule (stateful document → full pages; reference data → tabbed manage; read-only → list+view), migrate the outliers, enforce with an arch test. | FR-A6 | A1 |
| **A6** | **IA consolidation.** Build the four clusters. Merge Stock on hand (3 lenses), Catalog setup (4 tabs), Data transfer (2 tabs), Pricing (4 tabs). Absorb Variants into Products. Relocate Reports and Settings into Inventory. Add redirects from every old URL. | FR-A1, A2 | A1, A5; A4 for the location lens |

### Phase B — UX optimisation

| # | Work | Requirements | Depends on |
|---|------|--------------|------------|
| **B1** | **Shell and navigation.** Give the landing page real navigation (IA-4). Stop the module bar wrapping at 1440px (IA-2). Remove the third nested scroll region (IA-3). | FR-B1, B9, B10 | A6 |
| **B2** | **Overview rebuild.** KPI tiles: stock value, SKUs stocked, warehouses, open alerts. Alert triage panel. Even widget grid. Period/as-of on every widget. | FR-B2, B3 | A6 |
| **B3** | **Chart correctness.** Cap or aggregate warehouse categories; legible axis labels; purposeful empty state instead of an empty axis frame. | FR-B4 | B2 |
| **B4** | **Table ergonomics.** Fit default columns to 1440px with secondary columns toggleable; scroll affordance where needed; tabular figures and consistent precision. | FR-B5, B11 | A6 |
| **B5** | **States and affordances.** Purposeful empty states per context. Policy-hidden actions become consistently absent or disabled-with-reason. | FR-B6, B7 | A6 |

## Verification

Each phase gates on `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, and
`php artisan test --compact` for the touched suites; `composer test` before merge.

The load-bearing tests are the ones that prove *nothing was lost*:

- `InventoryUrlCompatibilityTest` — every pre-consolidation URL resolves or redirects (SC-6, FR-A1).
- `InventoryNavigationTest` — ≤6 collapsed entries, 14 leaves, unique icons, label agreement.
- `StockOnHandLensTest` — merging three resources did not widen anyone's permissions.
- `InventoryDemoSeederTest` — `migrate:fresh --seed` yields a populated warehouse dataset (SC-4).

## Complexity Tracking

**Stock on hand is the one genuinely hard merge.** Three resources with different grains — stock
balance per SKU/warehouse, lot with expiry, serial-numbered device — become one page with four
lenses. Each lens keeps its own query, columns, filters, and permission. The risk is a
lowest-common-denominator page that serves none of the three audiences. Mitigation: lenses are
independent table configurations sharing only the page shell and filter bar, and each lens keeps
its existing permission gate.

**Locations (A4) touch six tables.** Kept additive and nullable, backfilled, then tightened, so no
step is destructive and each is independently revertable.

**Redirects are the safety net for A6.** Consolidation changes URLs the client may have bookmarked.
Every old route gets an explicit redirect, asserted by test, rather than relying on the client to
relearn navigation.
