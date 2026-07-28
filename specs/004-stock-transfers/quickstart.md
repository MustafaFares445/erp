# Quickstart & Validation: Stock Transfers

How to prove FI-4 works end-to-end. Details live in [plan.md](plan.md), [data-model.md](data-model.md), and [contracts/](contracts/); this is the run/validation guide only.

## Prerequisites

- Foundation (FI-0), warehouses (FI-1), and read-only stock/movement screens (FI-2) present; adjustments (FI-3) merged.
- `inventory.transfer.view|create|confirm` permissions seeded: `php artisan db:seed --class=Database\\Seeders\\InventoryPermissionSeeder`.
- Migrations run: `php artisan migrate` (adds `stock_transfers`, `stock_transfer_items`).
- Two active warehouses and at least one product variant with on-hand stock at the source (seed via `InventoryDemoSeeder`).

## Automated validation

Run the feature's tests (fast inner loop):

```bash
php artisan test --compact --filter=TransferResourceTest
php artisan test --compact --filter=ConfirmTransferTest
```

Full gate before finalizing (mirrors CI — Pint, Rector dry-run, PHPStan, 100% type + code coverage, Pest):

```bash
composer test
```

Formatting on touched files while iterating:

```bash
vendor/bin/pint --dirty --format agent
```

## Manual smoke scenarios (dashboard)

**A — Prepare a draft (US1).** Inventory → Transfers → New. Pick source A and destination B (B must be a *different* active warehouse; selecting A as both is blocked). Add two lines (variant + quantity > 0), save. Expect: status **Draft**, number shows "Assigned on confirmation", and Stock Levels for A and B are unchanged. Re-open, edit a line, remove a line — still a draft, still zero stock change.

**B — Confirm with sufficient stock (US2).** As a user with `inventory.transfer.confirm`, open the draft's View page → **Confirm** → confirm the dialog. Expect: status **Confirmed** with a `TRF-000001`-style number; source A available decreased by Σ quantity, destination B increased by Σ quantity; Stock Movements shows two new transfer rows per line (−q at A, +q at B) that link back to this transfer.

**C — Insufficient source stock is refused (US2/US3).** Prepare a draft whose quantity (or summed duplicate lines for one variant) exceeds A's *available*. Confirm. Expect: a danger notification with a shortfall message, status stays **Draft**, and **no** movement or balance change anywhere.

**D — Immutability, discard & restore (US3).** On a **Confirmed** transfer: no Edit/Delete controls; the edit URL 403s; Confirm cannot run again. On a **Draft**: Delete it → it disappears from the default list but appears under the **Trashed** filter → Restore → it returns as an editable Draft. Throughout, no stock ever changed for the discarded/restored draft.

**E — Segregation of duties (US2).** As a user with `create` but not `confirm`, open a draft you created: the **Confirm** action is absent and cannot be invoked. As a user with neither permission, the Transfers area 403s.

## What "done" looks like

- Scenarios A–E behave as described.
- `TransferResourceTest` and `ConfirmTransferTest` cover the [contract test obligations](contracts/) — paired movements, dual-balance, summed-duplicate availability, atomic rollback, balanced ledger, immutability, restore, permission gating, and all-lifecycle audit.
- `composer test` is green (including 100% type + code coverage).
- No changes leaked outside the FI-4 surface listed in the plan (no new permission/seeder/registry/ArchTest/FI-2 edits).
