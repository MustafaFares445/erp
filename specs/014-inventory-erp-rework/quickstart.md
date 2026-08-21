# Quickstart: Validating the Inventory ERP-Pattern Rework

**Feature**: `specs/014-inventory-erp-rework` | **Date**: 2026-07-27

How to prove this feature works end to end. Scenarios map to the spec's user stories and can be
run independently, in story order.

---

## Prerequisites

The uncommitted catalog-setup consolidation and location-annotation migration on the current
branch are the assumed baseline (A-011). Commit or stash them first so this feature's diff stays
readable.

```bash
php artisan migrate
```

```bash
php artisan db:seed
```

Local Xdebug is configured for coverage (`php.ini`, `xdebug.mode = develop,debug,coverage`), so
`composer test:coverage` works without extra setup.

---

## Scenario 1 — Unified operations (US1, highest risk)

**Run first.** This is the only story that touches balances.

The reconciliation gate, which must pass before anything else in this story is accepted:

```bash
php artisan test --compact --filter=OperationBackfillReconciliation
```

Expected: every `(product_variant_id, warehouse_id)` balance and the full movement ledger are
identical before and after backfill, and in-transit totals agree under both the legacy
`status = dispatched` derivation and the new `stage = in_transit` derivation.

Then the story suite:

```bash
php artisan test --compact --filter=InventoryOperation
```

Then confirm nothing regressed in the features this supersedes:

```bash
php artisan test --compact tests/Feature/Inventory
```

**Manual walkthrough** — Operations → Receipts → New:

1. Create a receipt with two lines, confirm, check the destination warehouse balance rose by
   exactly the confirmed quantity.
2. Create a delivery for more than is available. It should hold at **Waiting** naming the product
   and the shortfall, with no balance change.
3. Create an internal transfer, mark Ready, dispatch. The source on-hand drops; Stock Levels shows
   the quantity **in transit**, counted against neither warehouse.
4. Receive it at the destination. Destination on-hand rises; in-transit returns to zero.
5. Reopen the completed operation and try to edit it. Refused, with a pointer to raising a
   correcting operation.
6. Compare the stage bar across all three types. Identical, except the transfer showing
   **In Transit** between Ready and Done.

Expected throughout: the confirmation prompt states the resulting balance per line *before* you
commit (FR-010, SRS §5.1).

---

## Scenario 2 — Product record tabs (US2)

```bash
php artisan test --compact --filter=ProductRecord
```

**Manual walkthrough** — Products → open any product:

1. Seven tabs render: View, Edit, Attributes, Variants, Vendors, Quantities, IN/OUT.
2. Create a variant from the Variants tab without leaving the record.
3. Quantities shows on-hand, reserved, available, in-transit and damaged per warehouse, plus a
   total.
4. Vendors lists every supplier reference — name, supplier product number, country, price,
   currency — all against this one product.
5. Sign in as a user without pricing permission. Price columns disappear; the rest of the tab
   still works.
6. Follow an old `/product-variants` link. It should land on the parent product's Variants tab,
   not a 404.

**Query-count check** (G-5, no N+1): open Quantities and IN/OUT for a product stocked in several
warehouses and confirm the query count does not scale with row count. Laravel Boost's
`browser-logs` and the debug bar are both adequate here.

---

## Scenario 3 — Media (US3)

```bash
php artisan test --compact --filter=ProductMedia
```

**Manual walkthrough** — Products → Edit:

1. Upload an image. It becomes the main image and appears in the product list.
2. Upload two more, reorder them. The first in order is the main image everywhere.
3. Remove one. It disappears from every view.
4. Upload a `.txt` file, and an oversized image. Both rejected naming the reason — and critically,
   **existing images survive** (G-4).
5. Give a variant its own image; confirm it displays instead of the parent's. Remove it; confirm
   the parent's main image returns.

Confirm no new Composer dependency was added:

```bash
git diff --stat composer.json composer.lock
```

Expected: no output.

---

## Scenario 4 — Packages (US4)

```bash
php artisan test --compact --filter=Package
```

The test that matters most is the balance-invariance one — balances identical with and without
packages attached (contract G-6).

**Manual walkthrough**:

1. Configurations → Package Types → create "Box".
2. Products → Packages → create "Pack of → 10" of type Box in a warehouse.
3. Record an operation line against it. The Package column shows it.
4. Note the product's balances. They must be unchanged by the package's presence.
5. Try to delete the package. Refused, naming what references it.
6. Try to give the package a location belonging to a different warehouse. Refused.

---

## Scenario 5 — Navigation (US5, run last)

```bash
php artisan test --compact --filter=InventoryNavigation
```

**Manual walkthrough**:

1. Exactly four inventory menus: Operations, Products, Reporting, Configurations.
2. Every capability that existed before is reachable in at most two clicks.
3. Old links to Reservations and Returns land on the filter or tab that now hosts them.
4. Sign in as a restricted user. Forbidden entries are absent, and no empty menu renders.
5. Switch to Arabic. Navigation and the operation screens render right-to-left with translated
   labels (FR-040, SRS §5.1).

---

## Full gate

Before considering the feature done:

```bash
composer test
```

This mirrors `.github/workflows/tests.yml`. It must include `tests/Unit/ArchTest.php` passing
**without new exceptions added** — the new operation namespace must not appear in the
`InventoryStock` / `InventoryMovement` allowlist. If it does, a write surface bypassed the domain
services and Principle III's enforcement has been weakened rather than satisfied.

```bash
vendor/bin/pint --dirty --format agent
```

```bash
vendor/bin/phpstan analyse
```

The PHPStan baseline may only shrink. New entries are forbidden; where this feature touches a file
with existing baseline entries, remove the ones that no longer apply.

---

## Reference

- Data model, validation rules and backfill steps: [data-model.md](./data-model.md)
- Service surface, guarantees and error contract: [contracts/inventory-operations.md](./contracts/inventory-operations.md)
- Tab structure and permission gating: [contracts/product-record.md](./contracts/product-record.md)
- Media wiring and package rules: [contracts/packages-and-media.md](./contracts/packages-and-media.md)
- Decision rationale, including the A-002 reversal: [research.md](./research.md)
