# Quickstart: Warehouses, Locations & Read-Only Stock Visibility

A validation guide proving FI-1/FI-2 behave per spec. Implementation details live in `tasks.md` (from `/speckit-tasks`) and the code; this file is how you confirm the feature works.

## Prerequisites

- Dependencies installed: `composer install`, `npm install && npm run build`.
- App key + DB ready: `php artisan key:generate`, `php artisan migrate` (SQLite by default; engine unconfirmed — research R11). This feature's 5 new migrations run here.
- Permissions + System Admin seeded (FI-0): `php artisan db:seed`.
- Optional demo data for manual smoke: `php artisan db:seed --class=InventoryDemoSeeder`.
- No queue worker required (no queued work until FI-6).

## Contracts under validation

- [Warehouse resource](contracts/warehouse-resource.md) (FI-1)
- [Stock level resource](contracts/stock-level-resource.md) (FI-2, read-only)
- [Movement ledger resource](contracts/movement-ledger-resource.md) (FI-2, read-only)
- [Authorization & architecture guard](contracts/authorization.md)

Data shapes: [data-model.md](data-model.md) (ERD §6).

## Automated validation (authoritative)

```bash
# Fast inner loop — this feature's tests
php artisan test --compact tests/Feature/Filament/WarehouseResourceTest.php \
  tests/Feature/Filament/StockLevelResourceTest.php \
  tests/Feature/Filament/StockMovementResourceTest.php \
  tests/Unit/ArchTest.php

# Full CI-equivalent gate (pint, rector, phpstan max, 100% type + code coverage)
composer test
```

Expected: all green, including 100% type + code coverage for new files.

### Scenario checklist (maps to spec acceptance criteria)

1. **Warehouse CRUD** (US1 / FR-001, SC-001): create with unique code → saved + listed; add a location → listed; deactivate → `is_active=false`.
2. **Unique code** (US1 / FR-002, SC-002): duplicate code → field error, no record.
3. **Delete-blocked-when-referenced** (US1 / FR-005, SC-003): warehouse with stock/movement rows → delete denied, deactivation offered; unreferenced → soft-deleted (recoverable).
4. **Stock read-only** (US2 / FR-010, SC-004): `StockLevelResource` exposes no create/edit/delete; `canCreate()===false`; no edit/delete actions.
5. **Low-stock** (US2 / FR-011, SC-005): available ≤ reorder (reorder set) → listed + flagged; `reorder_level` null → not flagged (boundary + null edge cases).
6. **Stock filters / uniqueness** (US2 / FR-012, FR-013, SC-007, SC-008): warehouse + variant search narrows; same variant in N warehouses → N rows.
7. **Movement read-only/immutable** (US3 / FR-015): no create/edit/delete; signed quantity distinguishable ±.
8. **Movement source link** (US3 / FR-019, SC-006): cross-module source renders as a read-only link, not editable.
9. **Movement filters** (US3 / FR-017): type/warehouse/variant/date/source filters narrow the ledger.
10. **Permission gating** (FR-020): a role with only one `inventory.*.view` sees only that resource; others hidden + 403.
11. **Architecture guard** (research R1): `ArchTest` fails if any Filament class *outside* the read-only stock/movement namespaces references `InventoryStock`/`InventoryMovement`; the read-only resources are excepted and pass.

## Manual smoke (optional)

```bash
php artisan serve
php artisan db:seed --class=InventoryDemoSeeder
```

- Log in as the seeded System Admin → the Inventory group shows **Warehouses**, **Stock Levels**, **Stock Movements** as real screens (placeholders replaced automatically — research R2).
- Create a warehouse + location; confirm counts update.
- Open Stock Levels → confirm no create/edit/delete controls; toggle the low-stock filter.
- Open a movement → confirm the infolist shows a read-only source link.

## Definition of done

- [ ] 5 migrations + models (warehouses, locations, stocks, movements, + product_variants/products stub) created, ERD-faithful, engine-agnostic.
- [ ] WarehouseResource CRUD with unique code, locations RM, read-only stocks RM, soft-delete blocked-when-referenced.
- [ ] StockLevelResource + StockMovementResource strictly read-only, with low-stock flag, filters, and movement infolist source link.
- [ ] Three policies delegate to the FI-0 trait; per-resource hide/403 verified.
- [ ] Architecture guard refined (read-only namespaces excepted) + no-write behavior tests.
- [ ] `lang/en/admin.php` column/attribute keys added (lang/ar deferred — Open Question #8).
- [ ] `composer test` green (100% type + code coverage); `PanelAccessTest`/`DashboardPageTest` still pass.
